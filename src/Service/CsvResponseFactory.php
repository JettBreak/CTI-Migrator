<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams rows as an RFC 4180 CSV download, neutralising spreadsheet formula injection.
 */
final class CsvResponseFactory
{
    /**
     * @param list<string>             $header
     * @param iterable<list<?string>>  $rows
     */
    public function create(string $filename, array $header, iterable $rows): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($header, $rows): void {
            $out = fopen('php://output', 'w');
            $this->writeRow($out, $header);
            foreach ($rows as $row) {
                $this->writeRow($out, $row);
            }
            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename));

        return $response;
    }

    /**
     * Writes one CSV line to $handle (a response stream or a file).
     *
     * @param resource            $handle
     * @param list<?string>       $row
     */
    public function writeRow($handle, array $row): void
    {
        fputcsv($handle, array_map($this->sanitize(...), $row), escape: '');
    }

    /**
     * Prefixes values that a spreadsheet would evaluate as a formula (OWASP CSV injection).
     */
    public function sanitize(?string $value): string
    {
        $value ??= '';

        return '' !== $value && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }
}
