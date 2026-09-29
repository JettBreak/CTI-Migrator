<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Runs every request, command and the background worker in APP_TIMEZONE (e.g. Asia/Manila), so
     * dates are stored and shown in local time. Each app table records the offset its dates were
     * written in (the "timezone" column, see App\Entity\RecordsTimezone).
     */
    public function __construct(string $environment, bool $debug)
    {
        $timezone = $_SERVER['APP_TIMEZONE'] ?? $_ENV['APP_TIMEZONE'] ?? 'Asia/Manila';
        if (!\in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException(sprintf('APP_TIMEZONE "%s" is not a valid timezone, e.g. Asia/Manila.', $timezone));
        }
        date_default_timezone_set($timezone);

        parent::__construct($environment, $debug);
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
