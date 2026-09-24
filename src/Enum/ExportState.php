<?php

namespace App\Enum;

enum ExportState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    /** Completed, but the file was deleted after the retention period. */
    case Expired = 'expired';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Queued, self::Running => 'warning',
            self::Expired => 'neutral',
        };
    }

    public function isFinished(): bool
    {
        return self::Queued !== $this && self::Running !== $this;
    }
}
