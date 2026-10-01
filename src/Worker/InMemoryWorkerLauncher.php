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
    /** @var array<int, WorkerRole> pid => what it runs; one a test adds to $processes without a role runs batches */
    public array $roles = [];
    private int $nextPid = 4242;

    public function launch(WorkerRole $role): int
    {
        $pid = $this->nextPid++;
        $this->processes[$pid] = true;
        $this->roles[$pid] = $role;

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
        $running = [];
        foreach (array_keys(array_filter($this->processes)) as $pid) {
            $running[$pid] = $this->roles[$pid] ?? WorkerRole::Batches;
        }

        return $running;
    }
}
