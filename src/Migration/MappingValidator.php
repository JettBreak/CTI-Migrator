<?php

namespace App\Migration;

use App\Core\CoreAccount;
use App\Core\CoreAccountGateway;
use App\Core\CoreCard;
use App\Core\LinkXml;
use App\Entity\MigrationRow;

/**
 * Checks each mapping row (rename current_account to new_account) against live core data
 * and records the errors on the row.
 *
 * Runs once at upload, and again inside the core transaction (with row locks) right
 * before a batch is applied, so both passes enforce exactly the same rules.
 */
final class MappingValidator
{
    public const KEY_MAX_LENGTH = 30;    // prmaster.prkey
    private const XML_MAX_LENGTH = 250;  // prlinkxx.xml1

    public function __construct(private readonly CoreAccountGateway $core)
    {
    }

    /**
     * Rows can be validated a chunk at a time: pass the first mapping in the whole file for each of the
     * chunk's accounts (MigrationRowRepository::firstMappings()) so the one-account-one-number rules
     * still see every row. Rows must be given in file order.
     *
     * @param iterable<MigrationRow>            $rows
     * @param array<string, array{string, int}> $firstForCurrent current => [new, line] of its first mapping in the file
     * @param array<string, array{string, int}> $firstForNew     new => [current, line] of its first mapping in the file
     *
     * @return list<RenamePlan> one plan per account to rename (only meaningful when every row is valid)
     */
    public function validate(iterable $rows, bool $lockForUpdate = false, array $firstForCurrent = [], array $firstForNew = []): array
    {
        $rows = [...$rows];
        $currents = array_map(static fn (MigrationRow $r) => $r->getCurrentAccount(), $rows);
        $news = array_map(static fn (MigrationRow $r) => $r->getNewAccount(), $rows);
        $cardSeqs = array_map('intval', array_filter(array_map(static fn (MigrationRow $r) => $r->getCardRef(), $rows), 'ctype_digit'));

        $accounts = $this->core->findAccounts(array_values(array_filter($currents)), $lockForUpdate);
        $usedKeys = array_flip($this->core->findUsedKeys(array_values(array_filter($news)), $lockForUpdate));
        $cards = $this->core->findCards(array_values($cardSeqs));

        $newForCurrent = $firstForCurrent;  // current => [new, line]
        $currentForNew = $firstForNew;      // new => [current, line]
        $plans = [];
        foreach ($rows as $row) {
            $current = $row->getCurrentAccount();
            $new = $row->getNewAccount();
            $cardRef = $row->getCardRef();
            $card = ctype_digit($cardRef) ? ($cards[(int) $cardRef] ?? null) : null;
            $candidates = $accounts[$current] ?? [];
            $account = 1 === count($candidates) ? $candidates[0] : null;

            $errors = $this->check($row, $account, $candidates, $card, isset($usedKeys[$new]), $newForCurrent, $currentForNew);
            $row->recordValidation($errors, $card ? MaskedCard::format($card->maskedCard) : null, $card?->cardholder, $account ? count($account->links) : null);

            if ([] === $errors && null !== $account && !isset($plans[$current])) {
                $plans[$current] = new RenamePlan($account, $new);
            }
        }

        return array_values($plans);
    }

    /**
     * @param list<CoreAccount>                       $candidates
     * @param array<string, array{string, int}>       $newForCurrent
     * @param array<string, array{string, int}>       $currentForNew
     *
     * @return list<string>
     */
    private function check(MigrationRow $row, ?CoreAccount $account, array $candidates, ?CoreCard $card, bool $newInUse, array &$newForCurrent, array &$currentForNew): array
    {
        $current = $row->getCurrentAccount();
        $new = $row->getNewAccount();
        $cardRef = $row->getCardRef();
        $line = $row->getLineNumber();

        if ('' === $current || '' === $new) {
            return ['current_account and new_account are both required.'];
        }
        if (mb_strlen($new) > self::KEY_MAX_LENGTH) {
            return [sprintf('new_account is longer than %d characters.', self::KEY_MAX_LENGTH)];
        }
        if ($new === $current) {
            return ['new_account is the same as current_account.'];
        }

        $errors = [];

        // The same account may appear on several rows (one per linked card) but must always get the same new number.
        if (isset($newForCurrent[$current]) && $newForCurrent[$current][0] !== $new) {
            $errors[] = sprintf('current_account is mapped to %s on line %d; one account can only get one new number.', $newForCurrent[$current][0], $newForCurrent[$current][1]);
        }
        if (isset($currentForNew[$new]) && $currentForNew[$new][0] !== $current) {
            $errors[] = sprintf('new_account is already assigned to %s on line %d.', $currentForNew[$new][0], $currentForNew[$new][1]);
        }
        $newForCurrent[$current] ??= [$new, $line];
        $currentForNew[$new] ??= [$current, $line];

        if ([] === $candidates) {
            $errors[] = 'current_account not found in core.';
        } elseif (null === $account) {
            // Not several cards on one account (that is allowed): separate account records share this number.
            $errors[] = sprintf(
                'current_account matches %d core account records (%s); fix the duplicate account number in core first.',
                count($candidates),
                implode(', ', array_map(static fn (CoreAccount $a) => sprintf('seq %d / customer %d / branch %d', $a->seq, $a->customerSeq, $a->branchSeq), $candidates)),
            );
        }
        if ($newInUse) {
            $errors[] = 'new_account already exists in core.';
        }

        if ('' !== $cardRef) {
            if (!ctype_digit($cardRef)) {
                $errors[] = 'card_ref must be the numeric card reference from the source export (or left empty).';
            } elseif (null === $card) {
                $errors[] = 'card_ref not found in core.';
            } elseif (!$card->isLinkedTo($current)) {
                $errors[] = 'The card in card_ref is not linked to current_account.';
            }
        }

        foreach ($account?->links ?? [] as $link) {
            if (!LinkXml::namesAccount($link->xml, $current)) {
                $errors[] = sprintf('The core link record for card %d is inconsistent (xml1 does not name current_account).', $link->cardSeq);
            } elseif (strlen(LinkXml::migrate($link->xml, $current, $new)) > self::XML_MAX_LENGTH) {
                $errors[] = sprintf('The updated core link record for card %d would exceed %d characters.', $link->cardSeq, self::XML_MAX_LENGTH);
            }
        }

        return $errors;
    }
}
