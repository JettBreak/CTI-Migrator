<?php

namespace App\Message;

/** Asks the worker to write the CSV for App\Entity\ExportJob #$jobId (a card export or a batch report). */
final readonly class GenerateCardExport
{
    public function __construct(public int $jobId)
    {
    }
}
