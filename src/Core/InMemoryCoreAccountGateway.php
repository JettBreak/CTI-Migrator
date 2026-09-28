<?php

namespace App\Core;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Test double for the core database, seeded with the same customers as the directory fixtures.
 * Card 1006 is a second card of Maria's linked to the same account as card 1001.
 */
#[When(env: 'test')]
#[AsAlias(CoreAccountGateway::class)]
final class InMemoryCoreAccountGateway implements CoreAccountGateway
{
    /** @var array<int, array{no: string, customer: int, type: string, branch: int}> account seq => account */
    public array $accounts = [];
    /** @var array<int, array{customer: int, masked: string, holder: string}> card seq => card */
    private array $cards = [];
    /** @var list<array{card: int, account: int, xml: string}> */
    public array $links = [];
    /** @var list<array{from: string, to: string, user: string}> */
    public array $renames = [];
    /** @var list<array{from: string, to: string, user: string}> rollbacks, like $renames */
    public array $restores = [];
    private bool $inTransaction = false;

    public function __construct()
    {
        $accounts = [2001 => ['001-004568921', 100284, '10'], 2002 => ['001-009713450', 100316, '20'], 2003 => ['001-005688102', 100329, '10'], 2004 => ['001-002421965', 100351, '10'], 2005 => ['001-008245779', 100389, '20'],
            2006 => ['009-003821460', 100284, '20']];
        foreach ($accounts as $seq => [$no, $customer, $type]) {
            $this->accounts[$seq] = ['no' => $no, 'customer' => $customer, 'type' => $type, 'branch' => 7];
        }

        $holders = ['Maria L. Santos', 'Jonathan D. Cruz', 'Elaine P. Reyes', 'Carlo M. Navarro', 'Anne S. Garcia', 'Maria L. Santos'];
        $last4 = ['8821', '7310', '5298', '4094', '1173', '9902'];
        foreach ($holders as $i => $holder) {
            $cardSeq = 1001 + $i;
            $accountSeq = 1006 === $cardSeq ? 2001 : 2001 + $i;
            $this->cards[$cardSeq] = ['customer' => $this->accounts[$accountSeq]['customer'], 'masked' => '541286******'.$last4[$i], 'holder' => $holder];
            $this->links[] = ['card' => $cardSeq, 'account' => $accountSeq, 'xml' => LinkXml::accountTag($this->accounts[$accountSeq]['no']).'<ACCTTYPE>SAVINGS ACCOUNT</><ACCT>SA</>'];
        }
    }

    /** Simulates another core user creating a record with this key after a batch was validated. */
    public function addAccount(int $seq, string $accountNo): void
    {
        $this->accounts[$seq] = ['no' => $accountNo, 'customer' => 999999, 'type' => '10', 'branch' => 7];
    }

    /** Simulates that record being removed again in core. */
    public function removeAccount(int $seq): void
    {
        unset($this->accounts[$seq]);
    }

    public function transactional(callable $work): mixed
    {
        $snapshot = [$this->accounts, $this->links, $this->renames, $this->restores];
        $this->inTransaction = true;
        try {
            return $work();
        } catch (\Throwable $e) {
            [$this->accounts, $this->links, $this->renames, $this->restores] = $snapshot;
            throw $e;
        } finally {
            $this->inTransaction = false;
        }
    }

    public function findCards(array $cardSeqs): array
    {
        $found = [];
        foreach ($cardSeqs as $seq) {
            if (!isset($this->cards[$seq])) {
                continue;
            }
            $links = [];
            foreach ($this->links as $link) {
                if ($link['card'] === $seq) {
                    $account = $this->accounts[$link['account']];
                    $links[] = new CoreLink($link['account'], $account['no'], $account['type'], $link['xml']);
                }
            }
            $card = $this->cards[$seq];
            $found[$seq] = new CoreCard($seq, $card['customer'], $card['masked'], $card['holder'], $links);
        }

        return $found;
    }

    public function findAccounts(array $accountNos, bool $forUpdate = false): array
    {
        $found = [];
        foreach ($this->accounts as $seq => $account) {
            if (in_array($account['no'], $accountNos, true)) {
                $links = [];
                foreach ($this->links as $link) {
                    if ($link['account'] === $seq) {
                        $links[] = [$link['card'], $link['xml']];
                    }
                }
                $found[$account['no']][] = new CoreAccount($seq, $account['no'], $account['customer'], $account['type'], $account['branch'], CoreAccountLink::group($links));
            }
        }

        return $found;
    }

    /** Simulates core holding an identical copy of an existing link row (same card, account and xml1). */
    public function duplicateLink(int $cardSeq, int $accountSeq): void
    {
        foreach ($this->links as $link) {
            if ($link['card'] === $cardSeq && $link['account'] === $accountSeq) {
                $this->links[] = $link;

                return;
            }
        }
        throw new \LogicException(sprintf('No link between card %d and account seq %d.', $cardSeq, $accountSeq));
    }

    public function findUsedKeys(array $keys, bool $forUpdate = false): array
    {
        return array_values(array_intersect($keys, array_column($this->accounts, 'no')));
    }

    public function renameAccount(CoreAccount $account, string $newAccountNo, string $user): void
    {
        if (!$this->inTransaction) {
            throw new \LogicException('renameAccount() must run inside transactional().');
        }
        if (($this->accounts[$account->seq]['no'] ?? null) !== $account->accountNo) {
            throw new \RuntimeException(sprintf('Expected to rename 1 prmaster row for account seq %d, changed 0.', $account->seq));
        }
        $this->accounts[$account->seq]['no'] = $newAccountNo;
        foreach ($this->links as &$link) {
            if ($link['account'] === $account->seq) {
                $link['xml'] = LinkXml::migrate($link['xml'], $account->accountNo, $newAccountNo);
            }
        }
        unset($link);
        $this->renames[] = ['from' => $account->accountNo, 'to' => $newAccountNo, 'user' => $user];
    }

    public function restoreAccount(CoreAccount $account, string $restoredAccountNo, string $user): void
    {
        if (!$this->inTransaction) {
            throw new \LogicException('restoreAccount() must run inside transactional().');
        }
        if (($this->accounts[$account->seq]['no'] ?? null) !== $account->accountNo) {
            throw new \RuntimeException(sprintf('Expected to rename 1 prmaster row for account seq %d, changed 0.', $account->seq));
        }
        $this->accounts[$account->seq]['no'] = $restoredAccountNo;
        foreach ($this->links as &$link) {
            if ($link['account'] === $account->seq) {
                $link['xml'] = LinkXml::revert($link['xml'], $account->accountNo, $restoredAccountNo)
                    ?? throw new \RuntimeException(sprintf('Card link of account seq %d changed since it was replaced.', $account->seq));
            }
        }
        unset($link);
        $this->restores[] = ['from' => $account->accountNo, 'to' => $restoredAccountNo, 'user' => $user];
    }
}
