<?php

namespace App;

use App\Message\CleanupExports;
use App\Message\DisableDormantUsers;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Recurring maintenance, run by the background worker (it consumes the "scheduler_default" transport).
 */
#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run

            // Delete export files past their retention (see app.export.retention).
            ->add(RecurringMessage::every('1 hour', new CleanupExports()))
            // Disable accounts unused for app.security.dormant_after.
            ->add(RecurringMessage::every('1 hour', new DisableDormantUsers()))
        ;
    }
}
