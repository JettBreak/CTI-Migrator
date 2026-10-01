<?php

namespace App\Worker;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;

/**
 * Web-side control of the background workers, one per App\Worker\WorkerRole (exports; batches and maintenance), run
 * side by side so a long batch never holds up an export. Works out each one's state from its heartbeat and the
 * process table, and starts and stops them together with the one switch on the Background worker page (gracefully,
 * or forcibly as a last resort).
 */
final class WorkerSupervisor
{
    public const RUNNING = 'running';
    public const STARTING = 'starting';
    public const STOPPING = 'stopping';
    public const STOPPED = 'stopped';
    /** Overall only: one worker is up and the other is stopped (e.g. it crashed). Turning the switch on starts it. */
    public const PARTIAL = 'partial';

    private const STOP_KEY = 'worker.stop';
    /** How long a freshly launched process may take to report its first heartbeat. */
    private const START_GRACE = 30;
    /** An idle worker beats every 5 s; flag it if nothing was heard for this long. */
    private const UNRESPONSIVE_AFTER = 60;

    public function __construct(
        private readonly WorkerLauncher $launcher,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
        #[Autowire(service: 'cache.messenger.restart_workers_signal')] private readonly CacheItemPoolInterface $restartSignal,
        #[Autowire('%kernel.logs_dir%')] private readonly string $logsDir,
    ) {
    }

    /**
     * Both workers, and their overall state for the switch: running or stopped when both are, stopping while
     * either is, starting while one is and the other is up, and partial when one is up and the other stopped.
     *
     * @return array{state: string, workers: array<string, array<string, mixed>>, launch: ?array<string, mixed>, stop: ?array<string, mixed>}
     */
    public function status(): array
    {
        $stop = $this->get(self::STOP_KEY);
        $workers = [];
        foreach (WorkerRole::cases() as $role) {
            $workers[$role->value] = $this->workerStatus($role, $stop);
        }
        $states = array_column($workers, 'state');
        $state = match (true) {
            \in_array(self::STOPPING, $states, true) => self::STOPPING,
            [self::RUNNING] === array_unique($states) => self::RUNNING,
            [self::STOPPED] === array_unique($states) => self::STOPPED,
            !\in_array(self::STOPPED, $states, true) => self::STARTING,
            default => self::PARTIAL,
        };
        $launches = array_filter(array_column($workers, 'launch'));
        usort($launches, static fn (array $a, array $b) => $b['at'] <=> $a['at']);

        return ['state' => $state, 'workers' => $workers, 'launch' => $launches[0] ?? null, 'stop' => $stop];
    }

    /**
     * @param array<string, mixed>|null $stop the last stop request
     *
     * @return array{role: WorkerRole, state: string, pid: ?int, heartbeat: ?array<string, mixed>, launch: ?array<string, mixed>, unresponsive: bool, crashed: bool}
     */
    private function workerStatus(WorkerRole $role, ?array $stop): array
    {
        $heartbeat = $this->get($role->heartbeatKey());
        $launch = $this->get($role->launchKey());
        $now = time();

        $heartbeatAlive = null !== $heartbeat && null === $heartbeat['stoppedAt'] && $this->launcher->isAlive($heartbeat['pid']);
        $launchIsNewer = null !== $launch && (null === $heartbeat || $launch['at'] > $heartbeat['startedAt']);

        if ($heartbeatAlive && !$launchIsNewer) {
            $stopping = null !== $stop && $stop['at'] > $heartbeat['startedAt'];
            $state = $stopping ? self::STOPPING : self::RUNNING;
            $pid = $heartbeat['pid'];
        } elseif ($launchIsNewer && $now - $launch['at']->getTimestamp() < self::START_GRACE && $this->launcher->isAlive($launch['pid'])) {
            $state = self::STARTING;
            $pid = $launch['pid'];
        } else {
            $state = self::STOPPED;
            $pid = null;
        }

        return [
            'role' => $role,
            'state' => $state,
            'pid' => $pid,
            'heartbeat' => $heartbeat,
            'launch' => $launch,
            // Busy with a long export or batch: no beats until it finishes, which is expected.
            'unresponsive' => self::RUNNING === $state && null === $heartbeat['current'] && $now - $heartbeat['beatAt']->getTimestamp() > self::UNRESPONSIVE_AFTER,
            // It had been running, was never told to stop, and the process is gone.
            'crashed' => self::STOPPED === $state && null !== $heartbeat && null === $heartbeat['stoppedAt'] && !$launchIsNewer,
        ];
    }

    /** Whether the worker for $role is running or starting: what waits for it gets done. */
    public function isRunning(WorkerRole $role): bool
    {
        return \in_array($this->workerStatus($role, $this->get(self::STOP_KEY))['state'], [self::RUNNING, self::STARTING], true);
    }

    /**
     * Worker processes of this app that are running but are not ones this page follows: started elsewhere, or
     * started here but unable to write their heartbeat (e.g. the app cache directory is not writable), so they
     * look stopped. Lists the process table: meant for the Background worker page, not every page.
     *
     * @param array<string, mixed>|null $status status(), if already at hand
     *
     * @return array<int, WorkerRole> pid => what it runs
     */
    public function untracked(?array $status = null): array
    {
        $tracked = $this->trackedPids($status ?? $this->status());

        return array_filter($this->launcher->runningWorkers(), static fn (int $pid) => !\in_array($pid, $tracked, true), \ARRAY_FILTER_USE_KEY);
    }

    /**
     * Starts each worker that is stopped.
     *
     * @return bool false if both were already running or starting
     *
     * @throws WorkerAlreadyRunning when a worker this page does not follow is running in the place of one to start:
     *                              two could each take up the same export or batch
     */
    public function start(string $by): bool
    {
        $lock = $this->lockFactory->createLock('worker-control', 60);
        if (!$lock->acquire()) {
            return false;
        }
        try {
            $status = $this->status();
            $toStart = array_values(array_map(
                static fn (array $worker) => $worker['role'],
                array_filter($status['workers'], static fn (array $worker) => self::STOPPED === $worker['state']),
            ));
            if ([] === $toStart) {
                return false;
            }
            $blocking = array_keys(array_filter($this->untracked($status), static fn (WorkerRole $role) => \in_array($role, $toStart, true)));
            if ([] !== $blocking) {
                throw new WorkerAlreadyRunning($blocking);
            }
            foreach ($toStart as $role) {
                $pid = $this->launcher->launch($role);
                $this->set($role->launchKey(), ['pid' => $pid, 'at' => new \DateTimeImmutable(), 'by' => $by]);
                $this->logger->notice('Background worker ({role}) started by {by} (pid {pid})', ['role' => $role->value, 'by' => $by, 'pid' => $pid]);
            }

            return true;
        } finally {
            $lock->release();
        }
    }

    /** Asks both workers to exit after the message each is handling (an export or batch in progress finishes first). */
    public function stop(string $by): void
    {
        $now = new \DateTimeImmutable();
        $this->restartSignal->save($this->restartSignal->getItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY)->set(microtime(true)));
        $this->set(self::STOP_KEY, ['at' => $now, 'by' => $by]);
        $this->logger->notice('Background workers stop requested by {by}', ['by' => $by]);
    }

    /**
     * Kills both workers outright, e.g. when a graceful stop hangs, and any other worker of this app that is running
     * (see untracked()). An export in progress is lost.
     */
    public function forceStop(string $by): void
    {
        $status = $this->status();
        $pids = array_values(array_unique([...$this->trackedPids($status), ...array_keys($this->launcher->runningWorkers())]));
        foreach ($pids as $pid) {
            $this->launcher->kill($pid);
        }
        foreach ($status['workers'] as $worker) {
            $heartbeat = $worker['heartbeat'];
            if (null !== $heartbeat) {
                $heartbeat['stoppedAt'] = new \DateTimeImmutable();
                $heartbeat['current'] = null;
                $this->set($worker['role']->heartbeatKey(), $heartbeat);
            }
        }
        $this->set(self::STOP_KEY, ['at' => new \DateTimeImmutable(), 'by' => $by.' (forced)']);
        $this->logger->warning('Background workers force-stopped by {by} (pid {pids})', ['by' => $by, 'pids' => implode(', ', $pids) ?: 'none']);
    }

    /** @return array<string, list<string>> log file name => its last $lines non-empty lines, oldest first */
    public function logTail(int $lines = 30): array
    {
        $tails = [];
        foreach (WorkerRole::cases() as $role) {
            foreach ([$role->logName().'.log', $role->logName().'-error.log'] as $file) {
                $path = $this->logsDir.'/'.$file;
                if (!is_file($path) || 0 === filesize($path)) {
                    continue;
                }
                $handle = fopen($path, 'r');
                fseek($handle, max(0, filesize($path) - 32768)); // only the end of a possibly large log
                $content = (string) stream_get_contents($handle);
                fclose($handle);
                $tails[$file] = \array_slice(array_values(array_filter(preg_split('/\R/', $content), static fn (string $l) => '' !== trim($l))), -$lines);
            }
        }

        return $tails;
    }

    /**
     * @param array<string, mixed> $status
     *
     * @return list<int> the processes of the workers this page follows that are not stopped
     */
    private function trackedPids(array $status): array
    {
        return array_values(array_filter(array_map(
            static fn (array $worker) => self::STOPPED === $worker['state'] ? null : $worker['pid'],
            $status['workers'],
        )));
    }

    /** @return array<string, mixed>|null */
    private function get(string $key): ?array
    {
        $item = $this->cache->getItem($key);

        return $item->isHit() ? $item->get() : null;
    }

    /** @param array<string, mixed> $value */
    private function set(string $key, array $value): void
    {
        $this->cache->save($this->cache->getItem($key)->set($value));
    }
}
