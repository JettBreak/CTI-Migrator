<?php

namespace App\MessageHandler;

use App\Enum\ExportState;
use App\Message\GenerateCardExport;
use App\Repository\ExportJobRepository;
use App\Service\CardExport;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Writes a large card export to disk chunk by chunk, recording progress on the job as it goes.
 */
#[AsMessageHandler]
final class GenerateCardExportHandler
{
    public function __construct(
        private readonly ExportJobRepository $jobs,
        private readonly EntityManagerInterface $em,
        private readonly CardExport $export,
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
            $this->export->writeFile($job->getStatusFilter(), $this->export->pathFor($job), function (int $rows) use ($job): void {
                $job->addRows($rows);
                $this->em->flush();
            });
            $job->complete();
        } catch (\Throwable $e) {
            $this->logger->error('Card export #{id} failed: {message}', ['id' => $job->getId(), 'message' => $e->getMessage(), 'exception' => $e]);
            $job->fail('The export could not be generated. Details are in the application log.');
        }

        $this->em->flush();
    }
}
