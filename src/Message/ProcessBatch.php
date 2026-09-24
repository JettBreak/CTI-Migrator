<?php

namespace App\Message;

/**
 * Asks the worker to carry App\Entity\MigrationBatch #$batchId forward from its current status:
 * validate an uploaded file, or rename the accounts of an approved batch. $actor is the user who
 * started that step (uploader or approver); core records the renames under their name.
 */
final readonly class ProcessBatch
{
    public function __construct(
        public int $batchId,
        public string $actor,
    ) {
    }
}
