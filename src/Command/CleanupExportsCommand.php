<?php

namespace App\Command;

use App\Service\ExportCleaner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:exports:cleanup',
    description: 'Delete export files past their retention (also runs hourly inside the background worker)',
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
            'Retention %s: %d export(s) expired, %d interrupted, %d file(s) removed (%s MB).',
            $this->cleaner->retention(), $report['expired'], $report['interrupted'], $report['files'], number_format($report['bytes'] / 1048576, 1),
        ));

        return Command::SUCCESS;
    }
}
