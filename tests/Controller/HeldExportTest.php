<?php

namespace App\Tests\Controller;

use App\Entity\ExportJob;
use App\Message\GenerateCardExport;
use App\Tests\AppTestCase;
use App\Worker\QueueInspector;
use Symfony\Component\Messenger\Envelope;

/**
 * A queued export whose message a worker took and never finished (it crashed first) stays claimed in the
 * queue until the transport's redeliver_timeout: the Background worker and Exports pages say when it is
 * retried (App\Worker\QueueInspector).
 */
final class HeldExportTest extends AppTestCase
{
    protected function tearDown(): void
    {
        // Not part of the app's schema, so the next test's setUp would leave it (and its rows) behind.
        $this->em()->getConnection()->executeStatement('DROP TABLE IF EXISTS messenger_messages');
        parent::tearDown();
    }

    public function testPagesSayWhenAHeldExportIsRetried(): void
    {
        $this->loginAs('officer');
        $held = $this->queueExport(claimedMinutesAgo: 10);
        $this->queueExport(claimedMinutesAgo: null); // waiting its turn, not held

        $retryAt = (new \DateTimeImmutable('-10 minutes'))->modify('+'.QueueInspector::REDELIVER_TIMEOUT.' seconds')->format('H:i');

        $this->client->request('GET', '/worker');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.exception.notice', \sprintf('Export #%d was taken by a worker that stopped before starting it.', $held->getId()));
        self::assertSelectorTextContains('.exception.notice', 'hands it out again at '.$retryAt);
        self::assertSelectorTextContains('.facts', '1 held by a stopped worker, retried at '.$retryAt);

        $this->client->request('GET', '/exports');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'td small:contains("Held by a stopped worker")');
        self::assertSelectorTextContains('tbody', 'Held by a stopped worker · retried at '.$retryAt);
        self::assertSelectorExists('[data-controller="auto-refresh"][data-auto-refresh-interval-value="5"]', 'Another export can start any moment');
    }

    public function testWithOnlyHeldExportsThePageRefreshesWhenTheFirstIsRetried(): void
    {
        $this->loginAs('officer');
        $this->queueExport(claimedMinutesAgo: 10); // retried in 50 minutes

        $this->client->request('GET', '/exports');
        $every = (int) $this->client->getCrawler()->filter('[data-controller="auto-refresh"]')->attr('data-auto-refresh-interval-value');
        self::assertEqualsWithDelta(50 * 60 + 5, $every, 10);
        self::assertSelectorTextContains('.page-head', 'this page refreshes again when it is retried');
    }

    public function testNothingIsShownWithoutAQueueTable(): void
    {
        $this->loginAs('officer');
        $this->em()->getConnection()->executeStatement('DROP TABLE IF EXISTS messenger_messages');

        $this->client->request('GET', '/exports');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('td small:contains("Held by a stopped worker")');
    }

    /** A queued export and its message, as the doctrine transport stores it; claimed by a worker unless null. */
    private function queueExport(?int $claimedMinutesAgo): ExportJob
    {
        $job = new ExportJob('officer', null, 100);
        $this->em()->persist($job);
        $this->em()->flush();

        $connection = $this->em()->getConnection();
        // The tests run messages synchronously, so there is no queue table: make one like the transport's.
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $encoded = static::getContainer()->get('messenger.default_serializer')->encode(new Envelope(new GenerateCardExport($job->getId())));
        $utc = static fn (string $when) => (new \DateTimeImmutable($when, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $connection->insert('messenger_messages', [
            'body' => $encoded['body'],
            'headers' => json_encode($encoded['headers'] ?? []),
            'queue_name' => 'default',
            'created_at' => $utc('-11 minutes'),
            'available_at' => $utc('-11 minutes'),
            'delivered_at' => null === $claimedMinutesAgo ? null : $utc(\sprintf('-%d minutes', $claimedMinutesAgo)),
        ]);

        return $job;
    }
}
