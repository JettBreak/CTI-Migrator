<?php

namespace App\Controller;

use App\Enum\BatchStatus;
use App\Repository\MigrationBatchRepository;
use App\Service\MigrationDataService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The site root. Open to every signed-in user (see access_control) so that administrators, who
 * cannot see migration pages, are sent on to user administration instead of being refused.
 */
final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function dashboard(MigrationDataService $data, MigrationBatchRepository $batches): Response
    {
        if ($this->isGranted('ROLE_USER_ADMIN')) {
            return $this->redirectToRoute('app_admin_users');
        }
        $this->denyAccessUnlessGranted('ROLE_MIGRATION_OFFICER');

        return $this->render('migration/dashboard.html.twig', [
            'core' => $data->cardStats(),
            'renamed' => $batches->countRenamedAccounts(),
            'awaiting' => $batches->countByStatus(BatchStatus::AwaitingApproval),
            'needs_correction' => $batches->countByStatus(BatchStatus::Invalid) + $batches->countByStatus(BatchStatus::Failed) + $batches->countByStatus(BatchStatus::Halted),
            'recent' => $batches->findRecent(5),
        ]);
    }
}
