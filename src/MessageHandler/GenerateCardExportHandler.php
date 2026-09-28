<?php

namespace App\MessageHandler;

use App\Enum\ExportState;
use App\Message\GenerateCardExport;
use App\Repository\ExportJobRepository;
use App\Service\BatchReportExport;
use App\Service\CardExport;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Writes an export job's file to disk chunk by chunk, recording progress on the job as it goes: a large
 * card export, or one of the reports queued when a batch that skipped rows to correct completes.
 */
#[AsMessageHandler]
final class GenerateCardExportHandler
{
    public function __construct(
        private readonly ExportJobRepository $jobs,
        private readonly EntityManagerInterface $em,
        private readonly CardExport $export,
        private readonly BatchReportExport $batchReports,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateCardExport $message): void
    {
        $job = $this->jobs->find($message->jobId);
        if (null === $job || ExportState::Queued !== $job->getState()) {
            return; // deleted, or already handled (e.g. a duplicate delivery)
        }

        $job->start();
        $this->em->flush();

        try {
            $onChunk = function (int $cards, int $rows) use ($job): void {
                $job->addProgress($cards, $rows);
                $this->em->flush();
            };
            if ($job->getKind()->isBatchReport()) {
                $this->batchReports->writeFile($job, $this->export->pathFor($job), $onChunk);
            } else {
                $this->export->writeFile($job->getStatusFilter(), $this->export->pathFor($job), $onChunk);
            }
            $job->complete();
        } catch (\Throwable $e) {
            $this->logger->error('Export #{id} failed: {message}', ['id' => $job->getId(), 'message' => $e->getMessage(), 'exception' => $e]);
            $job->fail('The export could not be generated. Details are in the application log.');
        }

        $this->em->flush();
    }
}
