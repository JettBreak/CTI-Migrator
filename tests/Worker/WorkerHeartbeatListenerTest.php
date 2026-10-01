<?php

namespace App\Tests\Worker;

use App\Message\GenerateCardExport;
use App\Worker\WorkerHeartbeatListener;
use App\Worker\WorkerRole;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

final class WorkerHeartbeatListenerTest extends TestCase
{
    public function testRecordsTheWorkerLifecycle(): void
    {
        $cache = new ArrayAdapter();
        $listener = new WorkerHeartbeatListener($cache, new NullLogger(), 'var/share/test');
        $worker = new Worker(['exports' => new InMemoryTransport()], new MessageBus());
        $envelope = new Envelope(new GenerateCardExport(7));
        $key = WorkerRole::Exports->heartbeatKey();

        $listener->onStarted(new WorkerStartedEvent($worker));
        $state = $cache->getItem($key)->get();
        self::assertSame(getmypid(), $state['pid']);
        self::assertSame('exports', $state['role']);
        self::assertNull($state['current']);

        $listener->onReceived(new WorkerMessageReceivedEvent($envelope, 'exports'));
        self::assertSame('GenerateCardExport', $cache->getItem($key)->get()['current']['message']);

        $listener->onHandled(new WorkerMessageHandledEvent($envelope, 'exports'));
        $state = $cache->getItem($key)->get();
        self::assertSame(1, $state['handled']);
        self::assertNull($state['current']);
        self::assertSame('GenerateCardExport', $state['last']['message']);

        $listener->onFailed(new WorkerMessageFailedEvent($envelope, 'exports', new \RuntimeException('boom')));
        $state = $cache->getItem($key)->get();
        self::assertSame(1, $state['failed']);
        self::assertSame('GenerateCardExport: boom', $state['lastError']['message']);

        $listener->onStopped(new WorkerStoppedEvent($worker));
        self::assertNotNull($cache->getItem($key)->get()['stoppedAt']);
    }

    public function testIgnoresMessagesHandledOutsideAWorker(): void
    {
        $cache = new ArrayAdapter();
        (new WorkerHeartbeatListener($cache, new NullLogger(), 'var/share/test'))->onHandled(new WorkerMessageHandledEvent(new Envelope(new GenerateCardExport(1)), 'sync'));

        foreach (WorkerRole::cases() as $role) {
            self::assertFalse($cache->getItem($role->heartbeatKey())->isHit());
        }
    }

    public function testAConsumerOfOtherTransportsRecordsNothing(): void
    {
        $cache = new ArrayAdapter();
        $listener = new WorkerHeartbeatListener($cache, new NullLogger(), 'var/share/test');
        $listener->onStarted(new WorkerStartedEvent(new Worker(['failed' => new InMemoryTransport()], new MessageBus())));
        $listener->onReceived(new WorkerMessageReceivedEvent(new Envelope(new GenerateCardExport(1)), 'failed'));

        foreach (WorkerRole::cases() as $role) {
            self::assertFalse($cache->getItem($role->heartbeatKey())->isHit(), 'It is not one of the two workers the page follows');
        }
    }
}
