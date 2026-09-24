<?php

namespace App\Worker;

/**
 * Starts, probes and kills the background worker process (bin/console messenger:consume).
 */
interface WorkerLauncher
{
    /** Starts a detached worker that outlives the web request, and returns its PID. */
    public function launch(): int;

    public function isAlive(int $pid): bool;

    /** Last resort when a graceful stop does not finish. */
    public function kill(int $pid): void;
}
