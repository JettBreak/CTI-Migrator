<?php

namespace App\Worker;

use App\Entity\ExportJob;
use App\Enum\ExportState;
use App\Message\GenerateCardExport;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Finds queued exports that a worker took from the queue and never finished: it crashed or was killed
 * before the export started. Their message stays claimed until the transport's redeliver_timeout has
 * passed, and only then does a worker take it again, so the export looks stuck while the worker idles.
 * The Background worker and Exports pages say when it will be retried.
 */
final class QueueInspector
{
    /** Symfony's redeliver_timeout for the doctrine transport; config/packages/messenger.yaml keeps the default. */
    public const REDELIVER_TIMEOUT = 3600;
    /** The doctrine transport's default table (MESSENGER_TRANSPORT_DSN sets none). */
    private const TABLE = 'messenger_messages';
    /**
     * The exports transport's queue (config/packages/messenger.yaml), and the default queue, where exports
     * requested before exports had their own queue and worker may still be waiting.
     */
    private const QUEUES = ['exports', 'default'];

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'messenger.default_serializer')] private readonly SerializerInterface $serializer,
    ) {
    }

    /**
     * @return array<int, \DateTimeImmutable> id of each export still queued but held by a stopped worker => when
     *                                        the queue hands it out again (in the app's timezone)
     */
    public function heldExports(): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                \sprintf('SELECT body, headers, delivered_at FROM %s WHERE queue_name IN (?, ?) AND delivered_at IS NOT NULL', self::TABLE),
                self::QUEUES,
            );
        } catch (DbalException) {
            return []; // no queue table, e.g. with the synchronous transport of the tests
        }

        $held = [];
        foreach ($rows as $row) {
            try {
                $message = $this->serializer->decode(['body' => $row['body'], 'headers' => json_decode((string) $row['headers'], true) ?: []])->getMessage();
            } catch (\Throwable) {
                continue; // a message this app can no longer read is not an export
            }
            if (!$message instanceof GenerateCardExport) {
                continue;
            }
            // Claims are stored in UTC.
            $claimedAt = new \DateTimeImmutable((string) $row['delivered_at'], new \DateTimeZone('UTC'));
            $held[$message->jobId] = $claimedAt->modify(\sprintf('+%d seconds', self::REDELIVER_TIMEOUT))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        }
        if ([] === $held) {
            return [];
        }

        // While a worker is on an export, the export is Running: a claimed message whose export is still
        // Queued was never started.
        $queued = $this->em->getRepository(ExportJob::class)->findBy(['id' => array_keys($held), 'state' => ExportState::Queued]);
        $queuedIds = array_map(static fn (ExportJob $job) => $job->getId(), $queued);

        return array_intersect_key($held, array_flip($queuedIds));
    }
}
