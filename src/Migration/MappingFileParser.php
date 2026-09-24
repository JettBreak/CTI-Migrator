<?php

namespace App\Migration;

/**
 * Reads an uploaded replacement file. Required columns (any order, extra columns ignored):
 * current_account, new_account. card_ref is optional — the source export with new_account filled in works as-is.
 *
 * Files of any length are streamed row by row; nothing holds the whole file in memory.
 */
final class MappingFileParser
{
    public const REQUIRED_COLUMNS = ['current_account', 'new_account'];
    /** Longer values are cut here only to fit the column; the validator still rejects anything over 30. */
    private const CELL_MAX_LENGTH = 64;

    /**
     * Checks the header and counts the mapping rows, so a bad file is refused at upload
     * rather than after it has been queued.
     *
     * @throws InvalidMappingFile
     */
    public function inspect(string $path): int
    {
        $count = 0;
        foreach ($this->rows($path) as $_) {
            ++$count;
        }
        if (0 === $count) {
            throw new InvalidMappingFile('The file has a header but no mapping rows.');
        }

        return $count;
    }

    /**
     * @return \Generator<array{line: int, card_ref: string, current_account: string, new_account: string}>
     *
     * @throws InvalidMappingFile
     */
    public function rows(string $path): \Generator
    {
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            throw new InvalidMappingFile('The uploaded file could not be read.');
        }

        try {
            $header = fgetcsv($handle, escape: '');
            if (false === $header || [null] === $header) {
                throw new InvalidMappingFile('The file is empty.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]); // UTF-8 BOM from Excel
            $columns = array_map(static fn ($name) => strtolower(trim((string) $name)), $header);

            $missing = array_diff(self::REQUIRED_COLUMNS, $columns);
            if ([] !== $missing) {
                throw new InvalidMappingFile(sprintf('Missing column(s): %s. Start from the "Download current records" export and fill in new_account.', implode(', ', $missing)));
            }
            $index = array_flip($columns);

            $line = 1;
            while (false !== ($values = fgetcsv($handle, escape: ''))) {
                ++$line;
                if ([null] === $values || '' === trim(implode('', $values))) {
                    continue; // blank line
                }
                yield [
                    'line' => $line,
                    'card_ref' => isset($index['card_ref']) ? $this->cell($values, $index['card_ref']) : '',
                    'current_account' => $this->cell($values, $index['current_account']),
                    'new_account' => $this->cell($values, $index['new_account']),
                ];
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array<int, ?string> $values */
    private function cell(array $values, int $index): string
    {
        // Strip the apostrophe the CSV exporter adds to neutralise formulas, and stray whitespace.
        return mb_substr(ltrim(trim((string) ($values[$index] ?? '')), "'"), 0, self::CELL_MAX_LENGTH);
    }
}
