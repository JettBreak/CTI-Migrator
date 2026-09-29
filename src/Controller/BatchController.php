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
use App\Repository\MigrationRowRepository;
use App\Security\BatchVoter;
use App\Service\BatchRowPurger;
use App\Service\CsvResponseFactory;
use App\Worker\WorkerSupervisor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MIGRATION_OFFICER')]
#[Route('/migration')]
final class BatchController extends AbstractController
{
    private const ROWS_PER_PAGE = 100;
    /** How long after finishing the progress bar stays up for its end animation: over one 5-second refresh. */
    private const JUST_FINISHED = '-15 seconds';

    public function __construct(
        private readonly BatchWorkflow $workflow,
        private readonly MigrationBatchRepository $batches,
        private readonly WorkerSupervisor $worker,
    ) {
    }

    #[Route('', name: 'app_migration', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        // Everyone sees the batch list; only migration officers (not approvers) get the upload form.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(BatchVoter::UPLOAD);
        }
        $form = $this->isGranted(BatchVoter::UPLOAD) ? $this->createForm(MappingUploadType::class) : null;
        $form?->handleRequest($request);

        if ($form?->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();
            try {
                $batch = $this->workflow->upload($file->getPathname(), $file->getClientOriginalName(), $this->getUser()->getUserIdentifier());
                if ($batch->getRowCount() > $this->getParameter('app.batch.sync_max_rows')) {
                    $this->addFlash('success', sprintf('File received: %s rows are being validated in the background. This page shows the progress.', number_format($batch->getRowCount())));
                }

                return $this->redirectToRoute('app_batch_show', ['id' => $batch->getId()], Response::HTTP_SEE_OTHER);
            } catch (InvalidMappingFile $e) {
                $form->get('file')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('migration/index.html.twig', [
            'form' => $form,
            'batches' => $this->batches->findRecent(),
            'sync_max_rows' => $this->getParameter('app.batch.sync_max_rows'),
            'upload_max_size' => $this->getParameter('app.batch.upload_max_size'),
        ]);
    }

    #[Route('/batches/{id}', name: 'app_batch_show', methods: ['GET'])]
    public function show(
        MigrationBatch $batch,
        AuditEntryRepository $audit,
        MigrationRowRepository $rows,
        #[MapQueryParameter] int $page = 1,
        #[MapQueryParameter] bool $invalid = false,
    ): Response {
        $total = $invalid ? $batch->invalidRowCount() : $batch->getValidatedRowCount();
        $pageCount = max(1, (int) ceil($total / self::ROWS_PER_PAGE));
        $page = min(max(1, $page), $pageCount);
        $busy = $batch->getStatus()->busy();

        return $this->render('migration/batch.html.twig', [
            'batch' => $batch,
            'rows' => $rows->page($batch, $page, self::ROWS_PER_PAGE, $invalid),
            'page' => $page,
            'page_count' => $pageCount,
            'per_page' => self::ROWS_PER_PAGE,
            'total' => $total,
            'invalid_only' => $invalid,
            'busy' => $busy,
            // Data retention (BatchRowPurger): when the rows of a Rejected, Invalid or Failed batch are
            // (or were) permanently deleted, and whether that has happened.
            'rows_due_on' => BatchRowPurger::dueOn($batch),
            'rows_purged' => BatchRowPurger::applies($batch) && $batch->getRowCount() > 0 && !$rows->hasRows($batch),
            // Finished replacing or rolling back moments ago: the progress bar stays up to finish its
            // animation (the page's last auto-refresh lands in this window).
            'just_finished' => \in_array($batch->getStatus(), [BatchStatus::Completed, BatchStatus::RolledBack], true)
                && $batch->getProcessedAt() > new \DateTimeImmutable(self::JUST_FINISHED),
            'waiting_for_worker' => $busy && !$this->worker->isRunning(),
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

    #[Route('/batches/{id}/submit-valid', name: 'app_batch_submit_valid', methods: ['POST'])]
    #[IsCsrfTokenValid('batch-action')]
    #[IsGranted(BatchVoter::SUBMIT_VALID, 'batch')]
    public function submitValid(MigrationBatch $batch): Response
    {
        return $this->act($batch, fn (string $user) => $this->workflow->submitValidRows($batch, $user), 'Valid rows submitted for approval; the rows that need correction are skipped.');
    }

    #[Route('/batches/{id}/approve', name: 'app_batch_approve', methods: ['POST'])]
    #[IsCsrfTokenValid('batch-action')]
    #[IsGranted(BatchVoter::REVIEW, 'batch')]
    public function approve(MigrationBatch $batch): Response
    {
        $success = 'Batch approved; accounts replaced in core.'.($batch->skipsInvalidRows() ? ' Exports of the migrated rows and the rows to correct are queued on the Exports page.' : '');

        return $this->act($batch, fn (string $user) => $this->workflow->approve($batch, $user), $success);
    }

    #[Route('/batches/{id}/resume', name: 'app_batch_resume', methods: ['POST'])]
    #[IsCsrfTokenValid('batch-action')]
    #[IsGranted(BatchVoter::RESUME, 'batch')]
    public function resume(MigrationBatch $batch): Response
    {
        return $this->act($batch, fn (string $user) => $this->workflow->resume($batch, $user), 'Batch resumed; the remaining accounts were replaced in core.');
    }

    #[Route('/batches/{id}/reject', name: 'app_batch_reject', methods: ['POST'])]
    #[IsCsrfTokenValid('batch-action')]
    #[IsGranted(BatchVoter::REVIEW, 'batch')]
    public function reject(MigrationBatch $batch, Request $request): Response
    {
        $note = trim((string) $request->getPayload()->get('note')) ?: null;

        return $this->act($batch, fn (string $user) => $this->workflow->reject($batch, $user, $note ? mb_substr($note, 0, 1000) : null), 'Batch rejected.');
    }

    #[Route('/batches/{id}/rollback', name: 'app_batch_rollback_request', methods: ['POST'])]
    #[IsCsrfTokenValid('batch-action')]
    #[IsGranted(BatchVoter::ROLLBACK_REQUEST, 'batch')]
    public function requestRollback(MigrationBatch $batch, Request $request): Response
    {
        $reason = trim((string) $request->getPayload()->get('reason'));
        if ('' === $reason) {
            $this->addFlash('error', 'Give a reason for the rollback.');

            return $this->redirectToRoute('app_batch_show', ['id' => $batch->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->act($batch, fn (string $user) => $this->workflow->requestRollback($batch, $user, mb_substr($reason, 0, 1000)), 'Rollback requested. An approver has to approve it before anything changes in core.');
    }

    #[Route('/batches/{id}/rollback/approve', name: 'app_batch_rollback_approve', methods: ['POST'])]
    #[IsCsrfTokenValid('batch-action')]
    #[IsGranted(BatchVoter::ROLLBACK_REVIEW, 'batch')]
    public function approveRollback(MigrationBatch $batch): Response
    {
        return $this->act($batch, fn (string $user) => $this->workflow->approveRollback($batch, $user), 'Rollback done: the replaced accounts have their old numbers back.');
    }

    #[Route('/batches/{id}/rollback/reject', name: 'app_batch_rollback_reject', methods: ['POST'])]
    #[IsCsrfTokenValid('batch-action')]
    #[IsGranted(BatchVoter::ROLLBACK_REVIEW, 'batch')]
    public function rejectRollback(MigrationBatch $batch, Request $request): Response
    {
        $note = trim((string) $request->getPayload()->get('note')) ?: null;

        return $this->act($batch, fn (string $user) => $this->workflow->rejectRollback($batch, $user, $note ? mb_substr($note, 0, 1000) : null), 'Rollback rejected; nothing was changed in core.');
    }

    #[Route('/batches/{id}/rollback/resume', name: 'app_batch_rollback_resume', methods: ['POST'])]
    #[IsCsrfTokenValid('batch-action')]
    #[IsGranted(BatchVoter::ROLLBACK_RESUME, 'batch')]
    public function resumeRollback(MigrationBatch $batch): Response
    {
        return $this->act($batch, fn (string $user) => $this->workflow->resumeRollback($batch, $user), 'Rollback resumed and finished.');
    }

    #[Route('/batches/{id}/report.csv', name: 'app_batch_report', methods: ['GET'])]
    public function report(MigrationBatch $batch, MigrationRowRepository $rows, CsvResponseFactory $csv): Response
    {
        if (!\in_array($batch->getStatus(), [BatchStatus::Completed, BatchStatus::Halted], true)) {
            throw $this->createNotFoundException('Only completed or halted batches have a migration report.');
        }

        $lines = (static function () use ($batch, $rows): \Generator {
            foreach ($rows->stream($batch) as $row) {
                yield [$row->getCardRef(), $row->getCardDisplay(), $row->getCardholder(), $row->getCurrentAccount(), $row->getNewAccount(), (string) $row->getLinkedCards(),
                    $row->getAppliedAt() ? 'Completed' : 'Not applied', $row->getAppliedAt()?->format(\DATE_ATOM), $batch->getReviewedBy()];
            }
        })();

        return $csv->create(sprintf('migration-report-batch-%d.csv', $batch->getId()), ['card_ref', 'card_number', 'cardholder', 'old_account', 'new_account', 'linked_cards', 'migration_status', 'applied_at', 'approved_by'], $lines);
    }

    /**
     * Rows needing correction (filter=invalid), or rows not replaced yet (filter=pending), in the upload
     * format: fix them and upload the file again. The line and errors columns are ignored on upload.
     */
    #[Route('/batches/{id}/rows.csv', name: 'app_batch_rows', methods: ['GET'])]
    public function rowsCsv(MigrationBatch $batch, MigrationRowRepository $rows, CsvResponseFactory $csv, #[MapQueryParameter] string $filter = 'invalid'): Response
    {
        if (!\in_array($filter, ['invalid', 'pending'], true)) {
            throw $this->createNotFoundException();
        }

        $lines = (static function () use ($batch, $rows, $filter): \Generator {
            foreach ($rows->stream($batch, $filter) as $row) {
                yield [$row->getCardRef(), $row->getCurrentAccount(), $row->getNewAccount(), (string) $row->getLineNumber(), implode(' ', $row->getErrors())];
            }
        })();

        return $csv->create(sprintf('batch-%d-%s.csv', $batch->getId(), 'invalid' === $filter ? 'rows-to-correct' : 'rows-not-replaced'), ['card_ref', 'current_account', 'new_account', 'line', 'errors'], $lines);
    }

    /** @param callable(string): void $action */
    private function act(MigrationBatch $batch, callable $action, string $success): Response
    {
        try {
            $action($this->getUser()->getUserIdentifier());
            match ($batch->getStatus()) {
                BatchStatus::Failed, BatchStatus::Halted, BatchStatus::RollbackHalted => $this->addFlash('error', $batch->getFailureReason() ?? 'The batch could not be applied.'),
                BatchStatus::Processing => $this->addFlash('success', sprintf('Batch approved. %s account(s) are being replaced in core in the background; this page shows the progress.', number_format($batch->accountCount() - $batch->getAppliedAccountCount()))),
                BatchStatus::RollingBack => $this->addFlash('success', sprintf('Rollback approved. %s account(s) are getting their old numbers back in the background; this page shows the progress.', number_format($batch->getAppliedAccountCount() - $batch->getRolledBackAccountCount() - $batch->getRollbackSkippedCount()))),
                BatchStatus::RolledBack => $this->addFlash('success', $batch->getRollbackSkippedCount() > 0
                    ? sprintf('Rollback done: %s account(s) have their old number back; %s were left alone because core changed since (see the Exports page).', number_format($batch->getRolledBackAccountCount()), number_format($batch->getRollbackSkippedCount()))
                    : $success),
                default => $this->addFlash('success', $success),
            };
        } catch (BatchLocked $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_batch_show', ['id' => $batch->getId()], Response::HTTP_SEE_OTHER);
    }
}
