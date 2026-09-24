<?php

namespace App\Twig;

use App\Entity\ExportJob;
use App\Repository\ExportJobRepository;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * running_export(): the export the worker is generating right now, or null. Export buttons are
 * disabled while one runs (App\Controller\ExportController refuses new exports then too).
 */
final class ExportExtension implements ResetInterface
{
    private ExportJob|false|null $running = false; // false = not looked up yet in this request

    public function __construct(private readonly ExportJobRepository $jobs)
    {
    }

    #[AsTwigFunction('running_export')]
    public function runningExport(): ?ExportJob
    {
        if (false === $this->running) {
            $this->running = $this->jobs->findRunning();
        }

        return $this->running;
    }

    public function reset(): void
    {
        $this->running = false;
    }
}
