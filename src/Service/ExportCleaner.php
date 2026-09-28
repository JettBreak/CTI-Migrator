<?php

namespace App\Service;

use App\Entity\ExportJob;
use App\Enum\ExportState;
use App\Repository\ExportJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Keeps var/exports from growing forever:
 *  - completed exports older than the retention period lose their file and become "Expired";
 *  - jobs stuck "Running" for hours (worker killed mid-export) are marked failed;
 *  - leftover partial files and files without a live job are deleted.
 */
final class ExportCleaner
{
    private const LAST_RUN_KEY = 'exports.last_cleanup';
    private const STUCK_AFTER = '2 hours';

    public function __construct(
        private readonly ExportJobRepository $jobs,
        private readonly EntityManagerInterface $em,
        private readonly CardExport $export,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
        #[Autowire('%app.export.retention%')] private readonly string $retention,
        #[Autowire('%app.export.dir%')] private readonly string $directory,
    ) {
    }

    public function retention(): string
    {
        return $this->retention;
    }

    /** @return array{at: \DateTimeImmutable, by: string, expired: int, interrupted: int, files: int, bytes: int} */
    public function run(string $by): array
    {
        $now = new \DateTimeImmutable();
        $report = ['at' => $now, 'by' => $by, 'expired' => 0, 'interrupted' => 0, 'files' => 0, 'bytes' => 0];

        foreach ($this->jobs->findCompletedBefore($now->modify('-'.$this->retention)) as $job) {
            $this->delete($this->export->pathFor($job), $report);
            $job->expire();
            ++$report['expired'];
        }
        foreach ($this->jobs->findInState(ExportState::Running, $now->modify('-'.self::STUCK_AFTER)) as $job) {
            $job->fail('Interrupted: the worker stopped before this export finished. Request it again.');
            ++$report['interrupted'];
        }
        $this->em->flush();

        $this->removeStrayFiles($now, $report);

        $this->logger->info('Export cleanup by {by}: {expired} expired, {interrupted} interrupted, {files} file(s) / {bytes} bytes removed', $report);
        $this->cache->save($this->cache->getItem(self::LAST_RUN_KEY)->set($report));

        return $report;
    }

    /** @return array{at: \DateTimeImmutable, by: string, expired: int, interrupted: int, files: int, bytes: int}|null */
    public function lastRun(): ?array
    {
        $item = $this->cache->getItem(self::LAST_RUN_KEY);

        return $item->isHit() ? $item->get() : null;
    }

    /** @return array{files: int, bytes: int} */
    public function usage(): array
    {
        if (!is_dir($this->directory)) {
            return ['files' => 0, 'bytes' => 0];
        }
        $files = 0;
        $bytes = 0;
        foreach ((new Finder())->files()->in($this->directory)->depth(0) as $file) {
            ++$files;
            $bytes += $file->getSize();
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    /** @param array<string, mixed> $report */
    private function removeStrayFiles(\DateTimeImmutable $now, array &$report): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        foreach ((new Finder())->files()->in($this->directory)->depth(0) as $file) {
            $name = $file->getFilename();
            $age = $now->getTimestamp() - $file->getMTime();

            if (str_ends_with($name, '.part')) {
                // An export is written in about a minute; a day-old partial file is from a crashed worker.
                if ($age > 86400) {
                    $this->delete($file->getPathname(), $report);
                }
                continue;
            }

            if (preg_match('/^(?:cards|batch-export)-(\d+)\.csv$/', $name, $m)) {
                $job = $this->jobs->find((int) $m[1]);
                if (!$job instanceof ExportJob || ExportState::Completed !== $job->getState()) {
                    $this->delete($file->getPathname(), $report);
                }
            }
        }
    }

    /** @param array<string, mixed> $report */
    private function delete(string $path, array &$report): void
    {
        if (is_file($path)) {
            $report['bytes'] += (int) filesize($path);
            ++$report['files'];
            $this->filesystem->remove($path);
        }
    }
}
