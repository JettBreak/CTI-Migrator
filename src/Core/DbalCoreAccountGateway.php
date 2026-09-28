<?php

namespace App\Core;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\WhenNot;

#[WhenNot(env: 'test')]
#[AsAlias(CoreAccountGateway::class)]
final class DbalCoreAccountGateway implements CoreAccountGateway
{
    private const CHUNK = 500;
    /** Same transaction code/message type core's sp_updateaccount logs account edits with. */
    private const LOG_MSGTYPE = 41;
    private const LOG_TRXCODE = 990202;

    public function __construct(private readonly Connection $coreappConnection)
    {
    }

    public function transactional(callable $work): mixed
    {
        return $this->coreappConnection->transactional(static fn () => $work());
    }

    public function findCards(array $cardSeqs): array
    {
        $cards = [];
        foreach (array_chunk(array_values(array_unique($cardSeqs)), self::CHUNK) as $chunk) {
            // The PAN is detokenised and masked inside the database; only the masked form is returned.
            $cardRows = $this->coreappConnection->fetchAllAssociative(<<<'SQL'
                SELECT c.prseqno AS seq, c.cifseqno AS customer_seq,
                       CASE WHEN CHAR_LENGTH(c.pan) >= 13
                            THEN CONCAT(LEFT(c.pan, 6), REPEAT('*', CHAR_LENGTH(c.pan) - 10), RIGHT(c.pan, 4))
                            ELSE '****' END AS masked,
                       COALESCE(NULLIF(TRIM(CONCAT_WS(' ', cu.firstname, cu.middlename, cu.lastname)), ''), 'Unassigned customer') AS cardholder
                FROM (
                    SELECT p.prseqno, p.cifseqno, REPLACE(CoreSecurity.GetDataFromToken(p.TokenID), ' ', '') AS pan
                    FROM prmaster p
                    WHERE p.prtype = 'CARD' AND p.prseqno IN (:seqs)
                ) c
                LEFT JOIN customer cu ON cu.cifseqno = c.cifseqno
                SQL, ['seqs' => $chunk], ['seqs' => ArrayParameterType::INTEGER]);

            $links = [];
            $linkRows = $this->coreappConnection->fetchAllAssociative(<<<'SQL'
                SELECT l.prseqno AS card_seq, a.prseqno AS account_seq, a.prkey AS account_no, a.accttype, COALESCE(l.xml1, '') AS xml1
                FROM prlinkxx l
                JOIN prmaster a ON a.prseqno = l.pseqnolink AND a.prtype = 'ACCT'
                WHERE l.prtype = 'ACCT' AND l.prseqno IN (:seqs)
                ORDER BY l.prseqno, l.prptr
                SQL, ['seqs' => $chunk], ['seqs' => ArrayParameterType::INTEGER]);
            foreach ($linkRows as $row) {
                $links[(int) $row['card_seq']][] = new CoreLink((int) $row['account_seq'], (string) $row['account_no'], $row['accttype'], (string) $row['xml1']);
            }

            foreach ($cardRows as $row) {
                $seq = (int) $row['seq'];
                $cards[$seq] = new CoreCard($seq, (int) $row['customer_seq'], (string) $row['masked'], (string) $row['cardholder'], $links[$seq] ?? []);
            }
        }

        return $cards;
    }

    public function findAccounts(array $accountNos, bool $forUpdate = false): array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $accounts = [];
        foreach (array_chunk(array_values(array_unique($accountNos)), self::CHUNK) as $chunk) {
            $rows = $this->coreappConnection->fetchAllAssociative(
                "SELECT prseqno, prkey, cifseqno, accttype, brseqno FROM prmaster WHERE prtype = 'ACCT' AND prkey IN (:keys)".$lock,
                ['keys' => $chunk],
                ['keys' => ArrayParameterType::STRING],
            );
            if ([] === $rows) {
                continue;
            }

            $links = [];
            $linkRows = $this->coreappConnection->fetchAllAssociative(
                "SELECT pseqnolink AS account_seq, prseqno AS card_seq, COALESCE(xml1, '') AS xml1
                 FROM prlinkxx WHERE prtype = 'ACCT' AND pseqnolink IN (:seqs) ORDER BY prseqno".$lock,
                ['seqs' => array_map(static fn (array $r) => (int) $r['prseqno'], $rows)],
                ['seqs' => ArrayParameterType::INTEGER],
            );
            foreach ($linkRows as $row) {
                $links[(int) $row['account_seq']][] = [(int) $row['card_seq'], (string) $row['xml1']];
            }
            $links = array_map(CoreAccountLink::group(...), $links);

            foreach ($rows as $row) {
                $seq = (int) $row['prseqno'];
                $accounts[(string) $row['prkey']][] = new CoreAccount($seq, (string) $row['prkey'], (int) $row['cifseqno'], $row['accttype'], (int) $row['brseqno'], $links[$seq] ?? []);
            }
        }

        return $accounts;
    }

    public function findUsedKeys(array $keys, bool $forUpdate = false): array
    {
        $used = [];
        foreach (array_chunk(array_values(array_unique($keys)), self::CHUNK) as $chunk) {
            // With FOR UPDATE, InnoDB also gap-locks the (prkey, ...) index, so no one can insert these keys meanwhile.
            $used = [...$used, ...$this->coreappConnection->fetchFirstColumn(
                'SELECT DISTINCT prkey FROM prmaster WHERE prkey IN (:keys)'.($forUpdate ? ' FOR UPDATE' : ''),
                ['keys' => $chunk],
                ['keys' => ArrayParameterType::STRING],
            )];
        }

        return array_values(array_map('strval', $used));
    }

    public function renameAccount(CoreAccount $account, string $newAccountNo, string $user): void
    {
        if (!$this->coreappConnection->isTransactionActive()) {
            throw new \LogicException('renameAccount() must run inside transactional().');
        }
        $user = mb_substr($user, 0, 20);

        $updated = $this->coreappConnection->executeStatement(
            "UPDATE prmaster SET prkey = :new_no, useraudit = :user, wkstn = :wkstn
             WHERE prseqno = :seq AND prtype = 'ACCT' AND prkey = :old_no",
            ['new_no' => $newAccountNo, 'user' => $user, 'wkstn' => self::WORKSTATION, 'seq' => $account->seq, 'old_no' => $account->accountNo],
        );
        if (1 !== $updated) {
            throw new \RuntimeException(sprintf('Expected to rename 1 prmaster row for account seq %d, changed %d.', $account->seq, $updated));
        }

        // useraudit/wkstn feed core's own audit trigger (tr_prlinkxx_upd → sp_auditlog).
        foreach ($account->links as $link) {
            $updated = $this->coreappConnection->executeStatement(
                "UPDATE prlinkxx SET xml1 = :new_xml, useraudit = :user, wkstn = :wkstn
                 WHERE prtype = 'ACCT' AND prseqno = :card_seq AND pseqnolink = :account_seq AND COALESCE(xml1, '') = :old_xml",
                [
                    'new_xml' => LinkXml::migrate($link->xml, $account->accountNo, $newAccountNo),
                    'user' => $user,
                    'wkstn' => self::WORKSTATION,
                    'card_seq' => $link->cardSeq,
                    'account_seq' => $account->seq,
                    'old_xml' => $link->xml,
                ],
            );
            // Identical duplicate rows are renamed together, so they stay identical; any other count means core changed.
            if ($link->copies !== $updated) {
                throw new \RuntimeException(sprintf('Expected to update %d prlinkxx row(s) for card %d / account seq %d, changed %d.', $link->copies, $link->cardSeq, $account->seq, $updated));
            }
        }

        // Log the change the way core's sp_updateaccount does, plus the number it replaced.
        $logXml = sprintf('<AC>%s</><IP>%s</><IPADDR></><BRSEQNO>%d</><OVERUSER></><OLDACCTNO>%s</>', $newAccountNo, self::WORKSTATION, $account->branchSeq, $account->accountNo);
        $this->coreappConnection->executeStatement(
            "CALL sp_insertlogclixx(:msgtype, :trxcode, :brseqno, 0, 0, 'ACCT', '', :prkey, '', '', :prkey, '', '', '', '', :user, '', 'WEB', 'WEB', :wkstn, :xml)",
            ['msgtype' => self::LOG_MSGTYPE, 'trxcode' => self::LOG_TRXCODE, 'brseqno' => $account->branchSeq, 'prkey' => $newAccountNo, 'user' => $user, 'wkstn' => self::WORKSTATION, 'xml' => $logXml],
        );
    }
}
