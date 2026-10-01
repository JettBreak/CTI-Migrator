<?php

namespace App\Worker;

/**
 * Starting a worker while another of the same kind is running that the Background worker page does not follow
 * (App\Worker\WorkerSupervisor::start()): two could each take up the same export or batch.
 */
final class WorkerAlreadyRunning extends \RuntimeException
{
    /** @param list<int> $pids */
    public function __construct(public readonly array $pids)
    {
        parent::__construct(\sprintf(
            'a worker is already running (PID %s) but does not report to this page. Force stop it, then turn the worker on.',
            implode(', ', $pids),
        ));
    }
}
