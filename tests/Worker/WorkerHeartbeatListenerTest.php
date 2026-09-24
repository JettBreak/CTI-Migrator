<?php

namespace App\Tests\Worker;

use App\Message\GenerateCardExport;
use App\Worker\WorkerHeartbeatListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Worker;

final class WorkerHeartbeatListenerTest extends TestCase
{
    public function testRecordsTheWorkerLifecycle(): void
    {
        $cache = new ArrayAdapter();
        $listener = new WorkerHeartbeatListener($cache);
        $worker = new Worker([], new MessageBus());
        $envelope = new Envelope(new GenerateCardExport(7));

        $listener->onStarted(new WorkerStartedEvent($worker));
        $state = $cache->getItem(WorkerHeartbeatListener::KEY)->get();
        self::assertSame(getmypid(), $state['pid']);
        self::assertNull($state['current']);

        $listener->onReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        self::assertSame('GenerateCardExport', $cache->getItem(WorkerHeartbeatListener::KEY)->get()['current']['message']);

        $listener->onHandled(new WorkerMessageHandledEvent($envelope, 'async'));
        $state = $cache->getItem(WorkerHeartbeatListener::KEY)->get();
        self::assertSame(1, $state['handled']);
        self::assertNull($state['current']);
        self::assertSame('GenerateCardExport', $state['last']['message']);

        $listener->onFailed(new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('boom')));
        $state = $cache->getItem(WorkerHeartbeatListener::KEY)->get();
        self::assertSame(1, $state['failed']);
        self::assertSame('GenerateCardExport: boom', $state['lastError']['message']);

        $listener->onStopped(new WorkerStoppedEvent($worker));
        self::assertNotNull($cache->getItem(WorkerHeartbeatListener::KEY)->get()['stoppedAt']);
    }

    public function testIgnoresMessagesHandledOutsideAWorker(): void
    {
        $cache = new ArrayAdapter();
        (new WorkerHeartbeatListener($cache))->onHandled(new WorkerMessageHandledEvent(new Envelope(new GenerateCardExport(1)), 'sync'));

        self::assertFalse($cache->getItem(WorkerHeartbeatListener::KEY)->isHit());
    }
}
