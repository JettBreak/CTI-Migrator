<?php

namespace App\Service;

use App\Migration\MaskedCard;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class MigrationDataService
{
    /** The status shown for a prmaster row: its prstatus description, or "Status N" when none is defined. */
    private const STATUS_LABEL = "COALESCE(NULLIF(ps.description, ''), CONCAT('Status ', p.status))";

    /** prmaster rows of one prtype with their status label; %s receives extra WHERE conditions. */
    private const RECORDS_SQL = <<<'SQL'
        FROM prmaster p
        LEFT JOIN prstatus ps ON ps.prtype = p.prtype AND ps.accttype = p.accttype AND ps.status = p.status
        WHERE p.prtype = :prtype %s
        SQL;

    /**
     * Card rows. The PAN is detokenised and masked (first 6 + last 4) inside the
     * database, so the full card number never reaches the application.
     * %1$s receives the FROM/WHERE clause, %2$s the LIMIT clause, %3$s the status label expression.
     */
    private const CARD_SQL = <<<'SQL'
        SELECT CAST(c.prseqno AS CHAR) AS card_ref,
               CASE WHEN CHAR_LENGTH(c.pan) >= 13
                    THEN CONCAT(LEFT(c.pan, 6), REPEAT('*', CHAR_LENGTH(c.pan) - 10), RIGHT(c.pan, 4))
                    ELSE '****' END AS card,
               COALESCE(NULLIF(TRIM(CONCAT_WS(' ', cu.firstname, cu.middlename, cu.lastname)), ''), 'Unassigned customer') AS cardholder,
               CAST(c.cifseqno AS CHAR) AS customer_id,
               a.prkey AS account,
               CASE WHEN l.pseqnolink IS NULL THEN 'No linked account'
                    WHEN LOCATE(CONCAT('<ACCTNO>', a.prkey, '</>'), l.xml1) > 0 AND LOCATE('<ACCT>', l.xml1) > 0 THEN 'Verified'
                    ELSE 'XML mismatch' END AS link_validation,
               c.status_label AS status
        FROM (
            SELECT p.prseqno, p.cifseqno, %3$s AS status_label,
                   REPLACE(CoreSecurity.GetDataFromToken(p.TokenID), ' ', '') AS pan
            %1$s
            ORDER BY p.prseqno DESC
            %2$s
        ) c
        LEFT JOIN prlinkxx l ON l.prseqno = c.prseqno AND l.prtype = 'ACCT'
        LEFT JOIN prmaster a ON a.prseqno = l.pseqnolink AND a.prtype = 'ACCT'
        LEFT JOIN customer cu ON cu.cifseqno = c.cifseqno
        ORDER BY c.prseqno DESC
        SQL;

    /** Account rows; %1$s receives the status label expression, %2$s the FROM/WHERE clause. */
    private const ACCOUNT_SQL = <<<'SQL'
        SELECT p.prkey AS account,
               COALESCE((SELECT NULLIF(TRIM(CONCAT_WS(' ', cu.firstname, cu.middlename, cu.lastname)), '') FROM customer cu WHERE cu.cifseqno = p.cifseqno LIMIT 1), 'Unassigned customer') AS customer,
               CAST(p.cifseqno AS CHAR) AS customer_id,
               p.accttype AS type,
               '—' AS balance,
               %1$s AS status
        %2$s
        ORDER BY p.prseqno DESC
        LIMIT :limit OFFSET :offset
        SQL;

    public function __construct(
        private readonly Connection $coreappConnection,
        private readonly CacheInterface $cache,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
    }

    /** @return array{rows: list<array<string, string>>, total: int} */
    public function cardPage(int $page, int $perPage = 25, ?string $status = null): array
    {
        $offset = (max(1, $page) - 1) * $perPage;

        if ('test' === $this->environment) {
            $rows = array_map($this->formatCardRow(...), $this->filterFixtures($this->fixtureCards(), $status));

            return ['rows' => array_slice($rows, $offset, $perPage), 'total' => count($rows)];
        }

        [$from, $params] = $this->records('CARD', $status);
        $rows = $this->coreappConnection->fetchAllAssociative(
            sprintf(self::CARD_SQL, $from, 'LIMIT :limit OFFSET :offset', self::STATUS_LABEL),
            $params + ['limit' => $perPage, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return [
            'rows' => array_map($this->formatCardRow(...), $rows),
            'total' => (int) $this->coreappConnection->fetchOne('SELECT COUNT(*) '.$from, $params),
        ];
    }

    /**
     * Every card row (optionally of one status), in chunks of $chunkSize cards (used for exports).
     *
     * Walks prseqno downwards with one short query per chunk instead of one long query over the whole
     * table, so a large export never holds a single statement open for minutes. A card with several
     * linked accounts yields one row per link, so a chunk can hold more rows than cards.
     *
     * @return iterable<list<array<string, string>>>
     */
    public function cardChunks(?string $status = null, int $chunkSize = 1000): iterable
    {
        if ('test' === $this->environment) {
            yield from array_chunk(array_map($this->formatCardRow(...), $this->filterFixtures($this->fixtureCards(), $status)), $chunkSize);

            return;
        }

        $this->reconnectIfIdle();
        $before = \PHP_INT_MAX;
        do {
            [$from, $params] = $this->records('CARD', $status, $before);
            $rows = $this->coreappConnection->fetchAllAssociative(
                sprintf(self::CARD_SQL, $from, 'LIMIT :limit', self::STATUS_LABEL),
                $params + ['limit' => $chunkSize],
                ['limit' => ParameterType::INTEGER, 'before' => ParameterType::INTEGER],
            );
            if ([] === $rows) {
                return;
            }
            $before = min(array_map(static fn (array $row) => (int) $row['card_ref'], $rows));
            yield array_map($this->formatCardRow(...), $rows);
        } while (true);
    }

    /**
     * Every card row (optionally of one status).
     *
     * @return iterable<array<string, string>>
     */
    public function allCards(?string $status = null): iterable
    {
        foreach ($this->cardChunks($status) as $rows) {
            yield from $rows;
        }
    }

    /** @return array{rows: list<array<string, string>>, total: int} */
    public function accountPage(int $page, int $perPage = 25, ?string $status = null): array
    {
        $offset = (max(1, $page) - 1) * $perPage;

        if ('test' === $this->environment) {
            $rows = $this->filterFixtures($this->fixtureAccounts(), $status);

            return ['rows' => array_slice($rows, $offset, $perPage), 'total' => count($rows)];
        }

        [$from, $params] = $this->records('ACCT', $status);

        return [
            'rows' => $this->coreappConnection->fetchAllAssociative(
                sprintf(self::ACCOUNT_SQL, self::STATUS_LABEL, $from),
                $params + ['limit' => $perPage, 'offset' => $offset],
                ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
            ),
            'total' => (int) $this->coreappConnection->fetchOne('SELECT COUNT(*) '.$from, $params),
        ];
    }

    /**
     * The statuses in use for cards or accounts, with how many records have each.
     * Cached for 10 minutes: counting half a million core rows takes seconds and the numbers barely move.
     *
     * @param 'CARD'|'ACCT' $prtype
     *
     * @return array<string, int> status description => record count, alphabetical
     */
    public function statusCounts(string $prtype): array
    {
        if ('test' === $this->environment) {
            $counts = array_count_values(array_column('CARD' === $prtype ? $this->fixtureCards() : $this->fixtureAccounts(), 'status'));
            ksort($counts);

            return $counts;
        }

        return $this->cache->get('core_status_counts_'.$prtype, function (ItemInterface $item) use ($prtype): array {
            $item->expiresAfter(600);
            [$from, $params] = $this->records($prtype, null);

            return array_map('intval', $this->coreappConnection->fetchAllKeyValue(
                'SELECT '.self::STATUS_LABEL.' AS label, COUNT(*) '.$from.' GROUP BY label ORDER BY label',
                $params,
            ));
        });
    }

    /** @return array{cards: int, linked: int} */
    public function cardStats(): array
    {
        if ('test' === $this->environment) {
            return ['cards' => count($this->fixtureCards()), 'linked' => count($this->fixtureCards())];
        }

        $stats = $this->coreappConnection->fetchAssociative(<<<'SQL'
            SELECT COUNT(*) AS cards,
                   SUM(EXISTS(SELECT 1 FROM prlinkxx l WHERE l.prseqno = c.prseqno AND l.prtype = 'ACCT')) AS linked
            FROM prmaster c
            WHERE c.prtype = 'CARD'
            SQL);

        return ['cards' => (int) $stats['cards'], 'linked' => (int) $stats['linked']];
    }

    /**
     * FROM/WHERE clause selecting prmaster rows of $prtype, optionally only those with status label
     * $status and prseqno below $before.
     *
     * @return array{string, array<string, string|int>}
     */
    private function records(string $prtype, ?string $status, ?int $before = null): array
    {
        $params = ['prtype' => $prtype];
        $condition = '';
        if (null !== $status) {
            $condition .= ' AND '.self::STATUS_LABEL.' = :status';
            $params['status'] = $status;
        }
        if (null !== $before) {
            $condition .= ' AND p.prseqno < :before';
            $params['before'] = $before;
        }

        return [sprintf(self::RECORDS_SQL, $condition), $params];
    }

    /** A worker process can sit idle for hours; MySQL drops idle connections, so start fresh if it did. */
    private function reconnectIfIdle(): void
    {
        try {
            $this->coreappConnection->executeQuery('SELECT 1');
        } catch (\Doctrine\DBAL\Exception) {
            $this->coreappConnection->close();
        }
    }

    /**
     * @param list<array<string, string>> $rows
     *
     * @return list<array<string, string>>
     */
    private function filterFixtures(array $rows, ?string $status): array
    {
        return null === $status ? $rows : array_values(array_filter($rows, static fn (array $row) => $row['status'] === $status));
    }

    /**
     * @param array<string, string> $row
     *
     * @return array<string, string>
     */
    private function formatCardRow(array $row): array
    {
        $row['card'] = MaskedCard::format((string) $row['card']);

        return $row;
    }

    /** @return list<array<string, string>> */
    private function fixtureCards(): array
    {
        return [
            ['card_ref' => '1001', 'card' => '541286******8821', 'cardholder' => 'Maria L. Santos', 'customer_id' => 'CUS-100284', 'account' => '001-004568921', 'status' => 'Active'],
            ['card_ref' => '1002', 'card' => '541286******7310', 'cardholder' => 'Jonathan D. Cruz', 'customer_id' => 'CUS-100316', 'account' => '001-009713450', 'status' => 'Active'],
            ['card_ref' => '1003', 'card' => '541286******5298', 'cardholder' => 'Elaine P. Reyes', 'customer_id' => 'CUS-100329', 'account' => '001-005688102', 'status' => 'Active'],
            ['card_ref' => '1004', 'card' => '541286******4094', 'cardholder' => 'Carlo M. Navarro', 'customer_id' => 'CUS-100351', 'account' => '001-002421965', 'status' => 'Restricted'],
            ['card_ref' => '1005', 'card' => '541286******1173', 'cardholder' => 'Anne S. Garcia', 'customer_id' => 'CUS-100389', 'account' => '001-008245779', 'status' => 'Active'],
        ];
    }

    /** @return list<array<string, string>> */
    private function fixtureAccounts(): array
    {
        return [
            ['account' => '001-004568921', 'customer' => 'Maria L. Santos', 'customer_id' => 'CUS-100284', 'type' => 'Savings', 'balance' => '₱ 183,400.00', 'status' => 'Active'],
            ['account' => '001-009713450', 'customer' => 'Jonathan D. Cruz', 'customer_id' => 'CUS-100316', 'type' => 'Checking', 'balance' => '₱ 72,850.50', 'status' => 'Active'],
            ['account' => '001-005688102', 'customer' => 'Elaine P. Reyes', 'customer_id' => 'CUS-100329', 'type' => 'Savings', 'balance' => '₱ 645,901.18', 'status' => 'Active'],
            ['account' => '001-002421965', 'customer' => 'Carlo M. Navarro', 'customer_id' => 'CUS-100351', 'type' => 'Savings', 'balance' => '₱ 41,750.00', 'status' => 'Restricted'],
            ['account' => '001-008245779', 'customer' => 'Anne S. Garcia', 'customer_id' => 'CUS-100389', 'type' => 'Checking', 'balance' => '₱ 98,120.24', 'status' => 'Active'],
        ];
    }
}
