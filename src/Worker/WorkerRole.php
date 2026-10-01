<?php

namespace App\Worker;

/**
 * The app's two background workers, run side by side by the Background worker switch, so a long migration batch never
 * holds up an export:
 *   - Exports: prepares exports (the "exports" transport), one at a time;
 *   - Batches: runs migration batches (the "async" transport) and the recurring maintenance (the scheduler).
 *
 * Batches keeps the cache keys and log files of the single worker the app had before, so a worker started then is
 * still followed as the batches worker.
 */
enum WorkerRole: string
{
    case Exports = 'exports';
    case Batches = 'batches';

    public function label(): string
    {
        return match ($this) {
            self::Exports => 'Exports',
            self::Batches => 'Batches and maintenance',
        };
    }

    /** @return list<string> the messenger transports it consumes, in order */
    public function transports(): array
    {
        return match ($this) {
            self::Exports => ['exports'],
            self::Batches => ['async', 'scheduler_default'],
        };
    }

    /** The worker's role from the transports it consumes; null for any other consumer (e.g. a one-off in a terminal). */
    public static function fromTransports(array $transports): ?self
    {
        foreach (self::cases() as $role) {
            if ($transports === $role->transports()) {
                return $role;
            }
        }

        return null;
    }

    /** Where it records what it is doing in the app cache (App\Worker\WorkerHeartbeatListener). */
    public function heartbeatKey(): string
    {
        return self::Batches === $this ? 'worker.heartbeat' : 'worker.heartbeat.'.$this->value;
    }

    /** Where the Background worker switch records starting it (App\Worker\WorkerSupervisor). */
    public function launchKey(): string
    {
        return self::Batches === $this ? 'worker.launch' : 'worker.launch.'.$this->value;
    }

    /** Its output and error log, in var/log, without the extension. */
    public function logName(): string
    {
        return self::Batches === $this ? 'worker' : 'worker-'.$this->value;
    }
}
