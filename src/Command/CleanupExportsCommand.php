<?php

namespace App\Command;

use App\Service\ExportCleaner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:exports:cleanup',
    description: 'Delete export files past their retention and, under the fixed data-retention rule, the rows of Rejected, Invalid and Failed batches older than 90 days (also runs hourly inside the background worker)',
)]
final class CleanupExportsCommand
{
    public function __construct(private readonly ExportCleaner $cleaner)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $report = $this->cleaner->run('console');
        $io->success(sprintf(
            'Retention %s: %d export(s) expired, %d interrupted, %d file(s) removed (%s MB). Data retention: %s row(s) of %d batch(es) permanently deleted.',
            $this->cleaner->retention(), $report['expired'], $report['interrupted'], $report['files'], number_format($report['bytes'] / 1048576, 1),
            number_format($report['purged_rows']), $report['purged_batches'],
        ));

        return Command::SUCCESS;
    }
}
