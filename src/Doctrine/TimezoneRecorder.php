<?php

namespace App\Doctrine;

use App\Entity\RecordsTimezone;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/** Stamps new rows with the UTC offset their dates are written in (the app's APP_TIMEZONE). */
#[AsDoctrineListener(event: Events::prePersist)]
final class TimezoneRecorder
{
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof RecordsTimezone && '' === $entity->getTimezone()) {
            $entity->recordTimezone(self::current());
        }
    }

    /** The current offset, e.g. "UTC+08:00". */
    public static function current(): string
    {
        return 'UTC'.(new \DateTimeImmutable())->format('P');
    }
}
