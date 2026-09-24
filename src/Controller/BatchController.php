<?php

namespace App\Controller;

use App\Entity\MigrationBatch;
use App\Enum\BatchStatus;
use App\Form\MappingUploadType;
use App\Migration\BatchLocked;
use App\Migration\BatchWorkflow;
use App\Migration\InvalidMappingFile;
use App\Repository\AuditEntryRepository;
use App\Repository\MigrationBatchRepository;
use App\Security\BatchVoter;
use App\Service\CsvResponseFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MIGRATION_OFFICER')]
#[Route('/migration')]
final class BatchController extends AbstractController
{
    public function __construct(
        private readonly BatchWorkflow $workflow,
        private readonly MigrationBatchRepository $batches,
    ) {
    }

    #[Route('', name: 'app_migration', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $form = $this->createForm(MappingUploadType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();
            try {
                $batch = $this->workflow->upload($file->getPathname(), $file->getClientOriginalName(), $this->getUser()->getUserIdentifier());

                return $this->redirectToRoute('app_batch_show', ['id' => $batch->getId()], Response::HTTP_SEE_OTHER);
            } catch (InvalidMappingFile $e) {
                $form->get('file')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('migration/index.html.twig', [
            'form' => $form,
            'batches' => $this->batches->findRecent(),
        ]);
    }

    #[Route('/batches/{id}', name: 'app_batch_show', methods: ['GET'])]
    public function show(MigrationBatch $batch, AuditEntryRepository $audit): Response
    {
        return $this->render('migration/batch.html.twig', [
            'batch' => $batch,
            'audit' => $audit->findForBatch($batch),
        ]);
    }

    #[Route('/batches/{id}/submit', name: 'app_batch_submit', methods: ['POST'])]
    #[IsCsrfTokenValid('batch-action')]
    #[IsGranted(BatchVoter::SUBMIT, 'batch')]
    public function submit(MigrationBatch $batch): Response
    {
        return $this->act($batch, fn (string $user) => $this->workflow->submit($batch, $user), 'Batch submitted for approval.');
    }

    #[Route('/batches/{id}/approve', name: 'app_batch_approve', methods: ['POST'])]
    #[IsCsrfTokenValid('batch-action')]
    #[IsGranted(BatchVoter::REVIEW, 'batch')]
    public function approve(MigrationBatch $batch): Response
    {
        return $this->act($batch, function (string $user) use ($batch): void {
            $this->workflow->approveAndApply($batch, $user);
            if (BatchStatus::Failed === $batch->getStatus()) {
                $this->addFlash('error', $batch->getFailureReason() ?? 'The batch could not be applied.');
            }
        }, 'Batch approved; accounts renamed in core.');
    }

    #[Route('/batches/{id}/reject', name: 'app_batch_reject', methods: ['POST'])]
    #[IsCsrfTokenValid('batch-action')]
    #[IsGranted(BatchVoter::REVIEW, 'batch')]
    public function reject(MigrationBatch $batch, Request $request): Response
    {
        $note = trim((string) $request->getPayload()->get('note')) ?: null;

        return $this->act($batch, fn (string $user) => $this->workflow->reject($batch, $user, $note ? mb_substr($note, 0, 1000) : null), 'Batch rejected.');
    }

    #[Route('/batches/{id}/report.csv', name: 'app_batch_report', methods: ['GET'])]
    public function report(MigrationBatch $batch, CsvResponseFactory $csv): Response
    {
        if (BatchStatus::Completed !== $batch->getStatus()) {
            throw $this->createNotFoundException('Only completed batches have a migration report.');
        }

        $rows = [];
        foreach ($batch->getRows() as $row) {
            $rows[] = [$row->getCardRef(), $row->getCardDisplay(), $row->getCardholder(), $row->getCurrentAccount(), $row->getNewAccount(), (string) $row->getLinkedCards(), 'Completed', $row->getAppliedAt()?->format(\DATE_ATOM), $batch->getReviewedBy()];
        }

        return $csv->create(sprintf('migration-report-batch-%d.csv', $batch->getId()), ['card_ref', 'card_number', 'cardholder', 'old_account', 'new_account', 'linked_cards', 'migration_status', 'applied_at', 'approved_by'], $rows);
    }

    /** @param callable(string): void $action */
    private function act(MigrationBatch $batch, callable $action, string $success): Response
    {
        try {
            $action($this->getUser()->getUserIdentifier());
            if (BatchStatus::Failed !== $batch->getStatus()) {
                $this->addFlash('success', $success);
            }
        } catch (BatchLocked $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_batch_show', ['id' => $batch->getId()], Response::HTTP_SEE_OTHER);
    }
}
