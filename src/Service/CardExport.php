<?php

namespace App\Service;

use App\Entity\ExportJob;
use App\Enum\ExportKind;
use App\Enum\ExportState;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The card-account source export (also the upload template): immediate download for small
 * exports, file generation for large ones (see App\MessageHandler\GenerateCardExportHandler).
 */
final class CardExport
{
    public const HEADER = ['card_ref', 'card_number', 'cardholder', 'customer_id', 'current_account', 'new_account'];

    public function __construct(
        private readonly MigrationDataService $data,
        private readonly CsvResponseFactory $csv,
        private readonly Filesystem $filesystem,
        #[Autowire('%app.export.sync_max_rows%')] private readonly int $syncMaxRows,
        #[Autowire('%app.export.dir%')] private readonly string $directory,
    ) {
    }

    /** Estimated number of cards, from the (cached) status counts; null when core was too busy to count. */
    public function estimate(?string $status): ?int
    {
        $counts = $this->data->statusCounts('CARD');
        if (null === $counts) {
            return null;
        }

        return null === $status ? array_sum($counts) : ($counts[$status] ?? 0);
    }

    /** Whether the export is small enough to generate inside a web request. */
    public function fitsInRequest(int $estimate): bool
    {
        return $estimate <= $this->syncMaxRows;
    }

    public function response(?string $status): StreamedResponse
    {
        $rows = (function () use ($status): iterable {
            foreach ($this->data->allCards($status) as $row) {
                yield $this->toCsv($row);
            }
        })();

        return $this->csv->create('card-account-source.csv', self::HEADER, $rows);
    }

    /**
     * Writes the export to $path. The file only appears under its final name once complete.
     *
     * @param callable(int, int): void $onChunk called after each chunk with the cards and the rows it wrote
     */
    public function writeFile(?string $status, string $path, callable $onChunk): void
    {
        $this->filesystem->mkdir(\dirname($path));
        $partial = $path.'.part';
        $handle = fopen($partial, 'w');
        try {
            $this->csv->writeRow($handle, self::HEADER);
            foreach ($this->data->cardChunks($status) as $rows) {
                foreach ($rows as $row) {
                    $this->csv->writeRow($handle, $this->toCsv($row));
                }
                // A chunk never splits a card (chunks are cut by card), so this counts each card once.
                $onChunk(\count(array_unique(array_column($rows, 'card_ref'))), \count($rows));
            }
        } catch (\Throwable $e) {
            fclose($handle);
            $this->filesystem->remove($partial);
            throw $e;
        }
        fclose($handle);
        $this->filesystem->rename($partial, $path, true);
    }

    /** Where an export job's file is written, whatever its kind (see App\Service\BatchReportExport). */
    public function pathFor(ExportJob $job): string
    {
        return sprintf(ExportKind::Cards === $job->getKind() ? '%s/cards-%d.csv' : '%s/batch-export-%d.csv', $this->directory, $job->getId());
    }

    /** Completed and its file is still on disk (it can be removed by the retention cleanup or by hand). */
    public function isAvailable(ExportJob $job): bool
    {
        return ExportState::Completed === $job->getState() && is_file($this->pathFor($job));
    }

    /**
     * @param array<string, ?string> $row
     *
     * @return list<?string>
     */
    private function toCsv(array $row): array
    {
        return [$row['card_ref'], $row['card'], $row['cardholder'], $row['customer_id'], $row['account'], ''];
    }
}
