<?php

namespace App\Entity;

/**
 * An entity with dates, whose table has a "timezone" column: the UTC offset (e.g. UTC+08:00) its
 * dates were written in. Filled in when the row is first saved (App\Doctrine\TimezoneRecorder).
 */
interface RecordsTimezone
{
    public function getTimezone(): string;

    public function recordTimezone(string $timezone): void;
}
