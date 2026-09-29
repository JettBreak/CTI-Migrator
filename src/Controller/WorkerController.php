<?php

namespace App\Controller;

use App\Enum\ExportState;
use App\Repository\ExportJobRepository;
use App\Service\ExportCleaner;
use App\Worker\QueueInspector;
use App\Worker\WorkerSupervisor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Monitoring and on/off control of the background worker that prepares exports and runs cleanup. */
#[IsGranted('ROLE_MIGRATION_OFFICER')]
#[Route('/worker')]
final class WorkerController extends AbstractController
{
    public function __construct(
        private readonly WorkerSupervisor $supervisor,
        private readonly ExportCleaner $cleaner,
    ) {
    }

    #[Route('', name: 'app_worker', methods: ['GET'])]
    public function index(ExportJobRepository $jobs, QueueInspector $queue): Response
    {
        return $this->render('worker/index.html.twig', [
            'worker' => $this->supervisor->status(),
            'queued' => $jobs->countInState(ExportState::Queued),
            // Queued exports a stopped worker took and never started: export id => when they are retried.
            'held' => $queue->heldExports(),
            'running' => $jobs->countInState(ExportState::Running),
            'usage' => $this->cleaner->usage(),
            'retention' => $this->cleaner->retention(),
            'last_cleanup' => $this->cleaner->lastRun(),
            'logs' => $this->supervisor->logTail(),
        ]);
    }

    /** The on/off switch: a checkbox that submits "on=1" when ticked and nothing when cleared. */
    #[Route('/switch', name: 'app_worker_switch', methods: ['POST'])]
    #[IsCsrfTokenValid('worker')]
    public function switch(Request $request): Response
    {
        $user = $this->getUser()->getUserIdentifier();

        if ($request->getPayload()->getBoolean('on')) {
            try {
                $started = $this->supervisor->start($user);
                $this->addFlash($started ? 'success' : 'error', $started ? 'Background worker is starting.' : 'The worker is already running.');
            } catch (\Throwable $e) {
                $this->addFlash('error', 'The worker could not be started: '.$e->getMessage());
            }
        } else {
            $this->supervisor->stop($user);
            $this->addFlash('success', 'Background worker will stop after its current job.');
        }

        return $this->redirectToRoute('app_worker', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/force-stop', name: 'app_worker_force_stop', methods: ['POST'])]
    #[IsCsrfTokenValid('worker')]
    public function forceStop(): Response
    {
        $this->supervisor->forceStop($this->getUser()->getUserIdentifier());
        $this->addFlash('success', 'Background worker was killed. An export in progress will be marked interrupted by the next cleanup.');

        return $this->redirectToRoute('app_worker', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/cleanup', name: 'app_worker_cleanup', methods: ['POST'])]
    #[IsCsrfTokenValid('worker')]
    public function cleanup(): Response
    {
        $report = $this->cleaner->run($this->getUser()->getUserIdentifier());
        $this->addFlash('success', sprintf('Cleanup done: %d export(s) expired, %d file(s) removed, rows of %d batch(es) permanently deleted under data retention.', $report['expired'], $report['files'], $report['purged_batches']));

        return $this->redirectToRoute('app_worker', status: Response::HTTP_SEE_OTHER);
    }
}
