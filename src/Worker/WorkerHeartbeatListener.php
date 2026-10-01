<?php

namespace App\Worker;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * Runs inside a worker process and records what it is doing in the shared app cache, under its role's key
 * (App\Worker\WorkerRole::heartbeatKey()), where App\Worker\WorkerSupervisor (web side) reads it for the monitoring
 * page. A consumer of other transports (e.g. a one-off in a terminal) records nothing.
 */
final class WorkerHeartbeatListener
{
    private const BEAT_EVERY = 5; // seconds, while idle

    /** @var array<string, mixed>|null */
    private ?array $state = null;
    private ?WorkerRole $role = null;
    private int $lastWrite = 0;
    private bool $saveFailed = false;

    public function __construct(
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.share_dir%')] private readonly string $shareDir,
    ) {
    }

    #[AsEventListener]
    public function onStarted(WorkerStartedEvent $event): void
    {
        $transports = $event->getWorker()->getMetadata()->getTransportNames();
        $this->role = WorkerRole::fromTransports($transports);
        if (null === $this->role) {
            return;
        }
        $now = new \DateTimeImmutable();
        $this->state = [
            'pid' => getmypid(),
            'role' => $this->role->value,
            'startedAt' => $now,
            'beatAt' => $now,
            'transports' => $transports,
            'handled' => 0,
            'failed' => 0,
            'current' => null,
            'last' => null,
            'lastError' => null,
            'stoppedAt' => null,
        ];
        $this->write(true);
    }

    #[AsEventListener]
    public function onRunning(WorkerRunningEvent $event): void
    {
        $this->write(false);
    }

    #[AsEventListener]
    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->update(['current' => ['message' => self::name($event->getEnvelope()->getMessage()), 'since' => new \DateTimeImmutable()]]);
    }

    #[AsEventListener]
    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $this->update([
            'handled' => ($this->state['handled'] ?? 0) + 1,
            'current' => null,
            'last' => ['message' => self::name($event->getEnvelope()->getMessage()), 'at' => new \DateTimeImmutable()],
        ]);
    }

    #[AsEventListener]
    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $this->update([
            'failed' => ($this->state['failed'] ?? 0) + 1,
            'current' => null,
            'lastError' => ['message' => self::name($event->getEnvelope()->getMessage()).': '.$event->getThrowable()->getMessage(), 'at' => new \DateTimeImmutable()],
        ]);
    }

    #[AsEventListener]
    public function onStopped(WorkerStoppedEvent $event): void
    {
        $this->update(['current' => null, 'stoppedAt' => new \DateTimeImmutable()]);
    }

    /** @param array<string, mixed> $changes */
    private function update(array $changes): void
    {
        if (null === $this->state) {
            return; // not a worker process (e.g. messages handled inline)
        }
        $this->state = $changes + $this->state;
        $this->write(true);
    }

    private function write(bool $force): void
    {
        if (null === $this->state || null === $this->role || (!$force && time() - $this->lastWrite < self::BEAT_EVERY)) {
            return;
        }
        $this->state['beatAt'] = new \DateTimeImmutable();
        $this->lastWrite = time();
        if (!$this->cache->save($this->cache->getItem($this->role->heartbeatKey())->set($this->state)) && !$this->saveFailed) {
            // Once per worker: the Background worker page cannot see it then, and would otherwise offer to start another.
            $this->saveFailed = true;
            $this->logger->error('The worker cannot save its heartbeat to the app cache, so the Background worker page cannot see it. Check that {dir} exists and belongs to the user this worker runs as, then restart the worker.', ['dir' => $this->shareDir]);
        }
    }

    private static function name(object $message): string
    {
        return (new \ReflectionClass($message))->getShortName();
    }
}
