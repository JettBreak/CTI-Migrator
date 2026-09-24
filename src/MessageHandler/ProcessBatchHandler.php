<?php

namespace App\MessageHandler;

use App\Message\ProcessBatch;
use App\Migration\BatchWorkflow;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Validates or applies a large batch in the background worker, recording progress on the batch as it goes.
 */
#[AsMessageHandler]
final class ProcessBatchHandler
{
    public function __construct(private readonly BatchWorkflow $workflow)
    {
    }

    public function __invoke(ProcessBatch $message): void
    {
        $this->workflow->process($message->batchId, $message->actor);
    }
}
