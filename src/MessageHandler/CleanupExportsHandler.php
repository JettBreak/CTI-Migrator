<?php

namespace App\MessageHandler;

use App\Message\CleanupExports;
use App\Service\ExportCleaner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CleanupExportsHandler
{
    public function __construct(private readonly ExportCleaner $cleaner)
    {
    }

    public function __invoke(CleanupExports $message): void
    {
        $this->cleaner->run('scheduler');
    }
}
