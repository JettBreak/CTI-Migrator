<?php

namespace App\Command;

use App\Entity\MigrationBatch;
use App\Enum\BatchStatus;
use App\Message\ProcessBatch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Puts a background batch back on the worker queue after its ProcessBatch message was lost, e.g.
 * the worker failed it (failed messages are not retried) or the queue was cleared. The batch
 * continues from wherever it is, exactly as the original message would have.
 */
#[AsCommand(
    name: 'app:batch:requeue',
    description: 'Queue a stuck batch (Importing or Processing) for the background worker again',
)]
final class RequeueBatchCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /** @param list<string> $ids */
    public function __invoke(SymfonyStyle $io, #[Argument('Batch number(s), e.g. 3')] array $ids): int
    {
        $failed = false;
        foreach ($ids as $id) {
            $batch = ctype_digit($id) ? $this->em->find(MigrationBatch::class, (int) $id) : null;
            $actor = match ($batch?->getStatus()) {
                BatchStatus::Importing => $batch->getUploadedBy(),
                BatchStatus::Processing => $batch->getReviewedBy() ?? $batch->getUploadedBy(),
                default => null,
            };
            if (null === $actor) {
                $io->error(null === $batch ? sprintf('Batch #%s not found.', $id) : sprintf('Batch #%s is %s; only Importing or Processing batches run in the background.', $id, $batch->getStatus()->value));
                $failed = true;
                continue;
            }

            $this->bus->dispatch(new ProcessBatch($batch->getId(), $actor));
            $io->success(sprintf('Batch #%d (%s) queued for the background worker.', $batch->getId(), $batch->getStatus()->value));
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
