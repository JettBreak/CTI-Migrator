<?php

namespace App\Repository;

use App\Entity\MigrationBatch;
use App\Entity\MigrationRow;
use App\Migration\MappingValidator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Batch rows, which can run into the millions: written in bulk, read a page or a chunk at a time,
 * and never loaded all at once.
 *
 * @extends ServiceEntityRepository<MigrationRow>
 */
class MigrationRowRepository extends ServiceEntityRepository
{
    /** Values per IN (...) list. */
    private const IN_CHUNK = 500;
    /** Subquery (one batch_id parameter): accounts of the batch with at least one row that needs correction. */
    private const ACCOUNTS_TO_CORRECT = '(SELECT current_account FROM migration_row WHERE batch_id = ? AND valid = 0)';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MigrationRow::class);
    }

    /**
     * Inserts validated rows with multi-row INSERTs; far faster than persisting entities one by one.
     *
     * @param list<MigrationRow> $rows
     */
    public function insert(MigrationBatch $batch, array $rows): void
    {
        $connection = $this->getEntityManager()->getConnection();
        // SQLite (tests) allows 999 bound values per statement; each row binds 10.
        $perStatement = $connection->getDatabasePlatform() instanceof SQLitePlatform ? 90 : 1000;
        $rowTypes = [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, Types::JSON, Types::BOOLEAN, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER];

        foreach (array_chunk($rows, $perStatement) as $chunk) {
            $params = [];
            $types = [];
            foreach ($chunk as $row) {
                array_push($params, $batch->getId(), $row->getLineNumber(), $row->getCardRef(), $row->getCurrentAccount(), $row->getNewAccount(),
                    $row->getErrors(), $row->isValid(), $row->getCardDisplay(), $row->getCardholder(), $row->getLinkedCards());
                array_push($types, ...$rowTypes);
            }
            $connection->executeStatement(
                'INSERT INTO migration_row (batch_id, line_number, card_ref, current_account, new_account, errors, valid, card_display, cardholder, linked_cards) VALUES '
                .implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')),
                $params,
                $types,
            );
        }
    }

    /** Whether any rows of $batch are still stored (see App\Service\BatchRowPurger). */
    public function hasRows(MigrationBatch $batch): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne('SELECT 1 FROM migration_row WHERE batch_id = ? LIMIT 1', [$batch->getId()]);
    }

    /**
     * Permanently deletes every row of $batch (see App\Service\BatchRowPurger) and returns how many.
     * A few thousand rows per statement, so no single delete holds locks for long on a large batch.
     */
    public function purgeForBatch(MigrationBatch $batch): int
    {
        $connection = $this->getEntityManager()->getConnection();
        // SQLite (tests) allows 999 bound values per statement.
        $chunk = $connection->getDatabasePlatform() instanceof SQLitePlatform ? 900 : 5000;
        $total = 0;
        while ($ids = $connection->fetchFirstColumn('SELECT id FROM migration_row WHERE batch_id = ? ORDER BY id LIMIT '.$chunk, [$batch->getId()])) {
            $total += $connection->executeStatement('DELETE FROM migration_row WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
        }

        return $total;
    }

    public function deleteForBatch(MigrationBatch $batch): void
    {
        $this->getEntityManager()->createQuery('DELETE FROM '.MigrationRow::class.' r WHERE r.batch = :batch')
            ->setParameter('batch', $batch)
            ->execute();
    }

    /**
     * The first mapping (lowest line) anywhere in the batch for each of $currents and each of $news, in the
     * shape MappingValidator tracks them: [current => [new, line]] and [new => [current, line]].
     * Lets it enforce "one account, one new number" across the whole file while seeing one chunk at a time.
     *
     * @param list<string> $currents
     * @param list<string> $news
     *
     * @return array{array<string, array{string, int}>, array<string, array{string, int}>}
     */
    public function firstMappings(MigrationBatch $batch, array $currents, array $news): array
    {
        return [
            $this->firstBy($batch, 'current_account', 'new_account', $currents),
            $this->firstBy($batch, 'new_account', 'current_account', $news),
        ];
    }

    /** @return array{rows: int, invalid: int, accounts: int} */
    public function stats(MigrationBatch $batch): array
    {
        [$rows, $invalid, $accounts] = $this->getEntityManager()->getConnection()->fetchNumeric(
            'SELECT COUNT(*), COALESCE(SUM(CASE WHEN valid = 0 THEN 1 ELSE 0 END), 0), COUNT(DISTINCT current_account) FROM migration_row WHERE batch_id = ?',
            [$batch->getId()],
        );

        return ['rows' => (int) $rows, 'invalid' => (int) $invalid, 'accounts' => (int) $accounts];
    }

    /** Rows renamed in core so far. */
    public function countApplied(MigrationBatch $batch): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM migration_row WHERE batch_id = ? AND applied_at IS NOT NULL',
            [$batch->getId()],
        );
    }

    /** Distinct accounts none of whose rows need correction: what a batch replaces when it skips rows to correct. */
    public function countFullyValidAccounts(MigrationBatch $batch): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(DISTINCT current_account) FROM migration_row WHERE batch_id = ? AND current_account NOT IN '.self::ACCOUNTS_TO_CORRECT,
            [$batch->getId(), $batch->getId()],
        );
    }

    /** @return list<MigrationRow> */
    public function page(MigrationBatch $batch, int $page, int $perPage, bool $invalidOnly = false): array
    {
        $query = $this->createQueryBuilder('r')
            ->where('r.batch = :batch')->setParameter('batch', $batch)
            ->orderBy('r.lineNumber')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        if ($invalidOnly) {
            $query->andWhere('r.valid = false');
        }

        return $query->getQuery()->getResult();
    }

    /**
     * Streams the batch's rows in file order, a chunk at a time, detaching each chunk once consumed.
     *
     * @param 'invalid'|'pending'|'applied'|'rollback_skipped'|null $filter rows needing correction, rows not renamed in core
     *                                                            yet, rows renamed, or rows a rollback left alone
     *
     * @return \Generator<MigrationRow>
     */
    public function stream(MigrationBatch $batch, ?string $filter = null, int $chunk = 1000): \Generator
    {
        $afterLine = 0;
        do {
            $query = $this->createQueryBuilder('r')
                ->where('r.batch = :batch AND r.lineNumber > :after')
                ->setParameter('batch', $batch)->setParameter('after', $afterLine)
                ->orderBy('r.lineNumber')
                ->setMaxResults($chunk);
            match ($filter) {
                'invalid' => $query->andWhere('r.valid = false'),
                'pending' => $query->andWhere('r.appliedAt IS NULL'),
                'applied' => $query->andWhere('r.appliedAt IS NOT NULL'),
                'rollback_skipped' => $query->andWhere('r.rollbackError IS NOT NULL'),
                null => null,
            };
            $rows = $query->getQuery()->getResult();
            foreach ($rows as $row) {
                yield $row;
                $afterLine = $row->getLineNumber();
            }
            foreach ($rows as $row) {
                $this->getEntityManager()->detach($row);
            }
        } while (count($rows) === $chunk);
    }

    /**
     * The next $limit accounts (in account-number order) that still have rows not renamed in core.
     * A batch that skips rows to correct leaves out every account with such a row, even its valid rows.
     *
     * @return list<string>
     */
    public function nextPendingAccounts(MigrationBatch $batch, int $limit): array
    {
        [$skip, $params] = $batch->skipsInvalidRows()
            ? [' AND current_account NOT IN '.self::ACCOUNTS_TO_CORRECT, [$batch->getId(), $batch->getId()]]
            : ['', [$batch->getId()]];
        $connection = $this->getEntityManager()->getConnection();
        $sql = $connection->getDatabasePlatform()->modifyLimitQuery(
            'SELECT DISTINCT current_account FROM migration_row WHERE batch_id = ? AND applied_at IS NULL'.$skip.' ORDER BY current_account',
            $limit,
        );

        return array_map('strval', $connection->fetchFirstColumn($sql, $params));
    }

    /**
     * @param list<string> $accounts
     *
     * @return list<MigrationRow> in the supplied account order, then file order for rows of one account
     */
    public function pendingRowsFor(MigrationBatch $batch, array $accounts): array
    {
        $rows = $this->createQueryBuilder('r')
            ->where('r.batch = :batch AND r.appliedAt IS NULL AND r.currentAccount IN (:accounts)')
            ->setParameter('batch', $batch)->setParameter('accounts', $accounts)
            ->orderBy('r.lineNumber')
            ->getQuery()->getResult();

        // The SQL IN predicate does not preserve $accounts' order. Keeping this aligned with
        // nextPendingAccounts() makes each chunk deterministic across SQLite and MySQL, while
        // retaining a mapping file's line order for duplicate card rows of the same account.
        $accountOrder = array_flip($accounts);
        usort($rows, static fn (MigrationRow $left, MigrationRow $right): int =>
            [$accountOrder[$left->getCurrentAccount()], $left->getLineNumber()]
            <=> [$accountOrder[$right->getCurrentAccount()], $right->getLineNumber()],
        );

        return $rows;
    }

    /**
     * @param list<string> $accounts
     *
     * @return int rows marked
     */
    public function markApplied(MigrationBatch $batch, array $accounts, \DateTimeImmutable $at): int
    {
        return $this->getEntityManager()->createQuery(
            'UPDATE '.MigrationRow::class.' r SET r.appliedAt = :at WHERE r.batch = :batch AND r.appliedAt IS NULL AND r.currentAccount IN (:accounts)',
        )
            ->setParameter('at', $at, Types::DATETIME_IMMUTABLE)
            ->setParameter('batch', $batch)
            ->setParameter('accounts', $accounts)
            ->execute();
    }

    /**
     * The next $limit replaced accounts (by their old number, in order) a rollback has not dealt with yet.
     *
     * @return list<string>
     */
    public function nextRollbackAccounts(MigrationBatch $batch, int $limit): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $sql = $connection->getDatabasePlatform()->modifyLimitQuery(
            'SELECT DISTINCT current_account FROM migration_row
             WHERE batch_id = ? AND rolled_back_at IS NULL AND applied_at IS NOT NULL AND rollback_error IS NULL ORDER BY current_account',
            $limit,
        );

        return array_map('strval', $connection->fetchFirstColumn($sql, [$batch->getId()]));
    }

    /**
     * @param list<string> $accounts old numbers
     *
     * @return array<string, string> old number => the new number the batch gave it
     */
    public function replacedNumbers(MigrationBatch $batch, array $accounts): array
    {
        return array_map('strval', $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT DISTINCT current_account, new_account FROM migration_row WHERE batch_id = ? AND applied_at IS NOT NULL AND current_account IN (?)',
            [$batch->getId(), $accounts],
            [ParameterType::INTEGER, ArrayParameterType::STRING],
        ));
    }

    /**
     * @param list<string> $accounts old numbers given back in core
     *
     * @return int rows marked
     */
    public function markRolledBack(MigrationBatch $batch, array $accounts, \DateTimeImmutable $at): int
    {
        if ([] === $accounts) {
            return 0;
        }

        return $this->getEntityManager()->createQuery(
            'UPDATE '.MigrationRow::class.' r SET r.rolledBackAt = :at
             WHERE r.batch = :batch AND r.appliedAt IS NOT NULL AND r.rolledBackAt IS NULL AND r.currentAccount IN (:accounts)',
        )
            ->setParameter('at', $at, Types::DATETIME_IMMUTABLE)
            ->setParameter('batch', $batch)
            ->setParameter('accounts', $accounts)
            ->execute();
    }

    /** Records why a rollback left $account alone (on each of its replaced rows). */
    public function markRollbackSkipped(MigrationBatch $batch, string $account, string $reason): void
    {
        $this->getEntityManager()->createQuery(
            'UPDATE '.MigrationRow::class.' r SET r.rollbackError = :reason
             WHERE r.batch = :batch AND r.appliedAt IS NOT NULL AND r.currentAccount = :account',
        )
            ->setParameter('reason', mb_substr($reason, 0, 255))
            ->setParameter('batch', $batch)
            ->setParameter('account', $account)
            ->execute();
    }

    /**
     * Rows a rollback gave their old number back, and rows it left alone.
     *
     * @return array{restored: int, skipped: int}
     */
    public function countRollback(MigrationBatch $batch): array
    {
        [$restored, $skipped] = $this->getEntityManager()->getConnection()->fetchNumeric(
            'SELECT COALESCE(SUM(rolled_back_at IS NOT NULL), 0), COALESCE(SUM(rollback_error IS NOT NULL), 0) FROM migration_row WHERE batch_id = ?',
            [$batch->getId()],
        );

        return ['restored' => (int) $restored, 'skipped' => (int) $skipped];
    }

    /**
     * @param list<string> $values
     *
     * @return array<string, array{string, int}>
     */
    private function firstBy(MigrationBatch $batch, string $key, string $other, array $values): array
    {
        $connection = $this->getEntityManager()->getConnection();
        // Only the rows MappingValidator registers: those that pass its basic checks.
        $usable = sprintf("current_account <> '' AND new_account <> '' AND new_account <> current_account AND %s <= %d",
            $connection->getDatabasePlatform()->getLengthExpression('new_account'), MappingValidator::KEY_MAX_LENGTH);

        $first = [];
        foreach (array_chunk(array_values(array_unique(array_filter($values, static fn (string $v) => '' !== $v))), self::IN_CHUNK) as $chunk) {
            $rows = $connection->fetchAllNumeric(
                "SELECT f.{$key}, f.{$other}, f.line_number FROM migration_row f
                 JOIN (SELECT MIN(line_number) AS line FROM migration_row
                       WHERE batch_id = :batch AND {$key} IN (:keys) AND {$usable} GROUP BY {$key}) m ON f.line_number = m.line
                 WHERE f.batch_id = :batch",
                ['batch' => $batch->getId(), 'keys' => $chunk],
                ['batch' => ParameterType::INTEGER, 'keys' => ArrayParameterType::STRING],
            );
            foreach ($rows as [$value, $mappedTo, $line]) {
                $first[(string) $value] = [(string) $mappedTo, (int) $line];
            }
        }

        return $first;
    }
}
