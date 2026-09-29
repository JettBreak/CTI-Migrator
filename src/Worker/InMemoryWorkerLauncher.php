<?php

namespace App\Worker;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\When;

/** Test double: pretends to start processes so tests never spawn a real worker. */
#[When(env: 'test')]
#[AsAlias(WorkerLauncher::class)]
final class InMemoryWorkerLauncher implements WorkerLauncher
{
    /** @var array<int, bool> pid => alive */
    public array $processes = [];
    private int $nextPid = 4242;

    public function launch(): int
    {
        $pid = $this->nextPid++;
        $this->processes[$pid] = true;

        return $pid;
    }

    public function isAlive(int $pid): bool
    {
        return $this->processes[$pid] ?? false;
    }

    public function kill(int $pid): void
    {
        $this->processes[$pid] = false;
    }

    /** Launched ones, and any a test adds to $processes as started elsewhere. */
    public function runningWorkers(): array
    {
        return array_keys(array_filter($this->processes));
    }
}
