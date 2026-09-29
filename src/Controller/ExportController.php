<?php

namespace App\Controller;

use App\Entity\ExportJob;
use App\Enum\ExportState;
use App\Message\GenerateCardExport;
use App\Repository\ExportJobRepository;
use App\Service\CardExport;
use App\Worker\WorkerSupervisor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MIGRATION_OFFICER')]
#[Route('/exports')]
final class ExportController extends AbstractController
{
    public function __construct(
        private readonly CardExport $export,
        private readonly ExportJobRepository $jobs,
    ) {
    }

    #[Route('', name: 'app_exports', methods: ['GET'])]
    public function index(WorkerSupervisor $worker): Response
    {
        $jobs = $this->jobs->findRecent();

        return $this->render('export/index.html.twig', [
            'worker_on' => $worker->isRunning(),
            'jobs' => $jobs,
            // Job id => whether its file can be downloaded right now.
            'available' => array_combine(array_map(static fn (ExportJob $j) => $j->getId(), $jobs), array_map($this->export->isAvailable(...), $jobs)),
            'refresh' => $this->jobs->hasUnfinished(),
            // Exports finished after this still show their progress bar, so its end animation plays
            // (the page's last auto-refresh lands in this window).
            'just_finished_since' => new \DateTimeImmutable('-15 seconds'),
            'sync_max_rows' => $this->getParameter('app.export.sync_max_rows'),
            'retention' => $this->getParameter('app.export.retention'),
        ]);
    }

    /**
     * Card-account source export (also the upload template), optionally one status only.
     * POST so browsers never re-run it on their own (retries of a slow GET hammered core).
     */
    #[Route('', name: 'app_export_request', methods: ['POST'])]
    #[IsCsrfTokenValid('card-export')]
    public function request(Request $request, EntityManagerInterface $em, MessageBusInterface $bus, WorkerSupervisor $worker): Response
    {
        // One export at a time: the buttons are disabled meanwhile, but a page opened earlier may still show them enabled.
        if (null !== $running = $this->jobs->findRunning()) {
            $this->addFlash('error', sprintf('Export #%d is still running. You can start another export when it has finished.', $running->getId()));

            return $this->redirectToRoute('app_exports', status: Response::HTTP_SEE_OTHER);
        }

        $status = trim((string) $request->getPayload()->get('status')) ?: null;
        $status = null === $status ? null : mb_substr($status, 0, 100);
        $estimate = $this->export->estimate($status);

        // Unknown size (core too busy to count): prepare it in the background rather than risk a huge download here.
        if (null !== $estimate && $this->export->fitsInRequest($estimate)) {
            return $this->export->response($status);
        }

        $job = new ExportJob($this->getUser()->getUserIdentifier(), $status, $estimate ?? 0);
        $em->persist($job);
        $em->flush();
        $bus->dispatch(new GenerateCardExport($job->getId()));

        $this->addFlash('success', null === $estimate
            ? sprintf('Export #%d is being prepared. Download it here when it is ready.', $job->getId())
            : sprintf('Export #%d of about %s cards is being prepared. Download it here when it is ready.', $job->getId(), number_format($estimate)));
        if (!$worker->isRunning() && ExportState::Queued === $job->getState()) {
            $this->addFlash('error', 'The background worker is off, so this export will wait until someone turns it on (Background worker page).');
        }

        return $this->redirectToRoute('app_exports', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/download', name: 'app_export_download', methods: ['GET'])]
    public function download(ExportJob $job): BinaryFileResponse
    {
        if (!$this->export->isAvailable($job)) {
            throw $this->createNotFoundException('This export is not available.');
        }

        $response = $this->file($this->export->pathFor($job), $job->downloadName(), ResponseHeaderBag::DISPOSITION_ATTACHMENT);
        // Set explicitly: MIME guessing needs the fileinfo extension, which this server lacks.
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');

        return $response;
    }
}
