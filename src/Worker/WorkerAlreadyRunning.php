<?php

namespace App\Worker;

/** Starting a worker while another is running (App\Worker\WorkerSupervisor::start()): the app runs one at a time. */
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
