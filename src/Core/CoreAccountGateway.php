<?php

namespace App\Core;

/**
 * Reads accounts/cards from the core banking database and renames account numbers.
 */
interface CoreAccountGateway
{
    /** Workstation id recorded in useraudit/wkstn columns and core's log. */
    public const WORKSTATION = 'UCPB-MIGRATION';

    /**
     * Runs $work in one core transaction; everything is rolled back if it throws.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transactional(callable $work): mixed;

    /**
     * @param list<int> $cardSeqs
     *
     * @return array<int, CoreCard> keyed by card seq
     */
    public function findCards(array $cardSeqs): array;

    /**
     * @param list<string> $accountNos
     * @param bool         $forUpdate lock the account and link rows until the transaction ends
     *
     * @return array<string, list<CoreAccount>> keyed by account number (a number can match several records)
     */
    public function findAccounts(array $accountNos, bool $forUpdate = false): array;

    /**
     * Which of $keys are already used as a prkey by any core record (account or card).
     *
     * @param list<string> $keys
     * @param bool         $forUpdate also stop other sessions inserting these keys until the transaction ends
     *
     * @return list<string>
     */
    public function findUsedKeys(array $keys, bool $forUpdate = false): array;

    /**
     * Renames $account to $newAccountNo: prmaster.prkey, every link's xml1 (<ACCTNO> + <OLDACCTNO>),
     * and a core log entry. Must run inside transactional().
     *
     * @throws \RuntimeException when core rows did not change exactly as expected
     */
    public function renameAccount(CoreAccount $account, string $newAccountNo, string $user): void;

    /**
     * Rollback of renameAccount(): $account (found by its current, new number) gets $restoredAccountNo
     * back, every link's xml1 is reverted (LinkXml::revert()), and a core log entry is written.
     * Must run inside transactional(); check first that every link can be reverted.
     *
     * @throws \RuntimeException when core rows did not change exactly as expected
     */
    public function restoreAccount(CoreAccount $account, string $restoredAccountNo, string $user): void;
}
