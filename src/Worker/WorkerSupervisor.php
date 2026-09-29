<?php

namespace App\Worker;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;

/**
 * Web-side control of the background worker: works out its state from the heartbeat and the
 * process table, starts it, and stops it (gracefully, or forcibly as a last resort).
 */
final class WorkerSupervisor
{
    public const RUNNING = 'running';
    public const STARTING = 'starting';
    public const STOPPING = 'stopping';
    public const STOPPED = 'stopped';

    private const LAUNCH_KEY = 'worker.launch';
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
     * @return array{state: string, pid: ?int, heartbeat: ?array<string, mixed>, launch: ?array<string, mixed>, stop: ?array<string, mixed>, unresponsive: bool, crashed: bool}
     */
    public function status(): array
    {
        $heartbeat = $this->get(WorkerHeartbeatListener::KEY);
        $launch = $this->get(self::LAUNCH_KEY);
        $stop = $this->get(self::STOP_KEY);
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
            'state' => $state,
            'pid' => $pid,
            'heartbeat' => $heartbeat,
            'launch' => $launch,
            'stop' => $stop,
            // Busy with a long export: no beats until it finishes, which is expected.
            'unresponsive' => self::RUNNING === $state && null === $heartbeat['current'] && $now - $heartbeat['beatAt']->getTimestamp() > self::UNRESPONSIVE_AFTER,
            // It had been running, was never told to stop, and the process is gone.
            'crashed' => self::STOPPED === $state && null !== $heartbeat && null === $heartbeat['stoppedAt'] && !$launchIsNewer,
        ];
    }

    public function isRunning(): bool
    {
        return \in_array($this->status()['state'], [self::RUNNING, self::STARTING], true);
    }

    /**
     * Worker processes of this app that are running but are not the one this page follows: started elsewhere, or
     * started here but unable to write their heartbeat (e.g. the app cache directory is not writable), so they
     * look stopped. Lists the process table: meant for the Background worker page, not every page.
     *
     * @param array<string, mixed>|null $status status(), if already at hand
     *
     * @return list<int>
     */
    public function untracked(?array $status = null): array
    {
        $status ??= $this->status();
        $tracked = self::STOPPED === $status['state'] ? null : $status['pid'];

        return array_values(array_filter($this->launcher->runningWorkers(), static fn (int $pid) => $pid !== $tracked));
    }

    /**
     * @return bool false if it was already running or starting
     *
     * @throws WorkerAlreadyRunning when a worker this page does not follow is running: the app runs one at a time,
     *                              and two could each take up the same export or batch
     */
    public function start(string $by): bool
    {
        $lock = $this->lockFactory->createLock('worker-control', 60);
        if (!$lock->acquire()) {
            return false;
        }
        try {
            $status = $this->status();
            if (self::STOPPED !== $status['state']) {
                return false;
            }
            if ([] !== $running = $this->launcher->runningWorkers()) {
                throw new WorkerAlreadyRunning($running);
            }
            $pid = $this->launcher->launch();
            $this->set(self::LAUNCH_KEY, ['pid' => $pid, 'at' => new \DateTimeImmutable(), 'by' => $by]);
            $this->logger->notice('Background worker started by {by} (pid {pid})', ['by' => $by, 'pid' => $pid]);

            return true;
        } finally {
            $lock->release();
        }
    }

    /** Asks the worker to exit after the message it is handling (an export in progress finishes first). */
    public function stop(string $by): void
    {
        $now = new \DateTimeImmutable();
        $this->restartSignal->save($this->restartSignal->getItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY)->set(microtime(true)));
        $this->set(self::STOP_KEY, ['at' => $now, 'by' => $by]);
        $this->logger->notice('Background worker stop requested by {by}', ['by' => $by]);
    }

    /**
     * Kills the process outright, e.g. when a graceful stop hangs, and any other worker of this app that is running
     * (see untracked()). An export in progress is lost.
     */
    public function forceStop(string $by): void
    {
        $status = $this->status();
        $pids = array_values(array_unique(array_filter([$status['pid'], ...$this->launcher->runningWorkers()])));
        foreach ($pids as $pid) {
            $this->launcher->kill($pid);
        }
        $heartbeat = $status['heartbeat'];
        if (null !== $heartbeat) {
            $heartbeat['stoppedAt'] = new \DateTimeImmutable();
            $heartbeat['current'] = null;
            $this->set(WorkerHeartbeatListener::KEY, $heartbeat);
        }
        $this->set(self::STOP_KEY, ['at' => new \DateTimeImmutable(), 'by' => $by.' (forced)']);
        $this->logger->warning('Background worker force-stopped by {by} (pid {pids})', ['by' => $by, 'pids' => implode(', ', $pids) ?: 'none']);
    }

    /** @return array<string, list<string>> log file name => its last $lines non-empty lines, oldest first */
    public function logTail(int $lines = 30): array
    {
        $tails = [];
        foreach (['worker.log', 'worker-error.log'] as $file) {
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

        return $tails;
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
