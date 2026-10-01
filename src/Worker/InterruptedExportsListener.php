<?php

namespace App\Worker;

use App\Entity\ExportJob;
use App\Enum\ExportState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * When the exports worker starts, marks exports still "Running" as interrupted: an exports worker that has just
 * started is not running any, so they belong to one that stopped mid-export (killed, crashed, or its cache was
 * rebuilt under it). Without this they would show "Running" until the hourly cleanup, hours later.
 *
 * Relies on one exports worker taking the exports queue at a time, as the Background worker switch runs it; the
 * heartbeat (App\Worker\WorkerHeartbeatListener) assumes the same. Their message, if it is handed out again, is then
 * ignored: the handler only runs queued exports.
 */
final class InterruptedExportsListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onStarted(WorkerStartedEvent $event): void
    {
        if (WorkerRole::Exports !== WorkerRole::fromTransports($event->getWorker()->getMetadata()->getTransportNames())) {
            return; // not the worker that runs exports
        }

        $jobs = $this->em->getRepository(ExportJob::class)->findBy(['state' => ExportState::Running]);
        foreach ($jobs as $job) {
            $job->interrupt();
            $this->logger->warning('Export #{id} was still running when the worker started: marked interrupted.', ['id' => $job->getId()]);
        }
        if ([] !== $jobs) {
            $this->em->flush();
        }
    }
}
