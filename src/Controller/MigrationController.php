<?php

namespace App\Controller;

use App\Enum\BatchStatus;
use App\Repository\MigrationBatchRepository;
use App\Service\MigrationDataService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MIGRATION_OFFICER')]
final class MigrationController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly MigrationDataService $data,
    ) {
    }

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function dashboard(MigrationBatchRepository $batches): Response
    {
        return $this->render('migration/dashboard.html.twig', [
            'core' => $this->data->cardStats(),
            'renamed' => $batches->countRenamedAccounts(),
            'awaiting' => $batches->countByStatus(BatchStatus::AwaitingApproval),
            'needs_correction' => $batches->countByStatus(BatchStatus::Invalid) + $batches->countByStatus(BatchStatus::Failed) + $batches->countByStatus(BatchStatus::Halted),
            'recent' => $batches->findRecent(5),
        ]);
    }

    #[Route('/cards', name: 'app_cards', methods: ['GET'])]
    public function cards(#[MapQueryParameter] int $page = 1, #[MapQueryParameter] ?string $status = null): Response
    {
        $status = $this->normaliseStatus($status);
        $result = $this->data->cardPage(max(1, $page), self::PER_PAGE, $status);

        return $this->renderList('cards', 'Card directory', 'Cardholder and linked account records', $page, $status, $result, $this->data->statusCounts('CARD') ?? []);
    }

    #[Route('/accounts', name: 'app_accounts', methods: ['GET'])]
    public function accounts(#[MapQueryParameter] int $page = 1, #[MapQueryParameter] ?string $status = null): Response
    {
        $status = $this->normaliseStatus($status);
        $result = $this->data->accountPage(max(1, $page), self::PER_PAGE, $status);

        return $this->renderList('accounts', 'Account directory', 'Customer account records', $page, $status, $result, $this->data->statusCounts('ACCT') ?? []);
    }

    private function normaliseStatus(?string $status): ?string
    {
        $status = trim((string) $status);

        return '' === $status ? null : mb_substr($status, 0, 100);
    }

    /**
     * @param array{rows: list<array<string, string>>, total: int} $result
     * @param array<string, int>                                   $statuses
     */
    private function renderList(string $type, string $title, string $subtitle, int $page, ?string $status, array $result, array $statuses): Response
    {
        $pageCount = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        return $this->render('migration/list.html.twig', [
            'title' => $title, 'subtitle' => $subtitle, 'rows' => $result['rows'], 'type' => $type,
            'page' => min(max(1, $page), $pageCount), 'page_count' => $pageCount,
            'total' => $result['total'], 'per_page' => self::PER_PAGE,
            'status' => $status, 'statuses' => $statuses,
        ]);
    }
}
