<?php

namespace App\Enum;

/** What an App\Entity\ExportJob writes. */
enum ExportKind: string
{
    /** Card-account source export (also the upload template); requested on the Exports page. */
    case Cards = 'cards';
    /** Rows a batch replaced in core; queued when a batch that skipped rows to correct completes. */
    case BatchMigrated = 'batch_migrated';
    /** Rows a batch left out (they need correction), in the upload format; queued with BatchMigrated. */
    case BatchCorrections = 'batch_corrections';

    public function label(): string
    {
        return match ($this) {
            self::Cards => 'Card-account source',
            self::BatchMigrated => 'Migrated rows',
            self::BatchCorrections => 'Rows to correct',
        };
    }

    public function isBatchReport(): bool
    {
        return self::Cards !== $this;
    }
}
