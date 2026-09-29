<?php

namespace App\Tests\Worker;

use App\Entity\ExportJob;
use App\Enum\ExportState;
use App\Tests\AppTestCase;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** An export a stopped worker left "Running" is marked interrupted when the next worker starts. */
final class InterruptedExportsListenerTest extends AppTestCase
{
    public function testExportsLeftRunningAreInterruptedWhenTheWorkerStarts(): void
    {
        $running = new ExportJob('officer', null, 500);
        $running->start();
        $queued = new ExportJob('officer', null, 500);
        $this->em()->persist($running);
        $this->em()->persist($queued);
        $this->em()->flush();

        $this->startWorker(['scheduler_default']);
        $this->em()->refresh($running);
        self::assertSame(ExportState::Running, $running->getState(), 'A worker that does not run exports leaves them alone');

        $this->startWorker(['async', 'scheduler_default']);
        $this->em()->refresh($running);
        $this->em()->refresh($queued);
        self::assertSame(ExportState::Failed, $running->getState());
        self::assertStringStartsWith('Interrupted: the worker stopped before this export finished.', $running->getError());
        self::assertSame(ExportState::Queued, $queued->getState(), 'Queued exports are still to be run');
    }

    /** @param list<string> $transports */
    private function startWorker(array $transports): void
    {
        $receivers = array_fill_keys($transports, new InMemoryTransport());
        $worker = new Worker($receivers, $this->createStub(MessageBusInterface::class));
        static::getContainer()->get(EventDispatcherInterface::class)->dispatch(new WorkerStartedEvent($worker));
    }
}
