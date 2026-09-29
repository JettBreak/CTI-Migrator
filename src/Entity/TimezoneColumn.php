<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** The "timezone" column of RecordsTimezone entities. */
trait TimezoneColumn
{
    /** UTC offset the row's dates are in, e.g. UTC+08:00. */
    #[ORM\Column(length: 9)]
    private string $timezone = '';

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function recordTimezone(string $timezone): void
    {
        $this->timezone = $timezone;
    }
}
