<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserChangeRequest;
use App\Enum\UserChangeType;
use App\Enum\UserRole;
use App\Form\NewAccountType;
use App\Repository\UserAuditEntryRepository;
use App\Repository\UserChangeRequestRepository;
use App\Repository\UserRepository;
use App\Security\AccountPolicy;
use App\Security\UserAdministration;
use App\Security\UserAdministrationException;
use App\Security\UserAdminVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * User administration, maker-checker: an administrator requests a new account or a change to one,
 * and a different administrator approves or rejects it. Temporary passwords (new accounts and
 * resets) are shown once, to the administrator who approved, to hand to the account's owner.
 */
#[IsGranted('ROLE_USER_ADMIN')]
#[Route('/admin')]
final class UserAdminController extends AbstractController
{
    /** Flash type holding [username, temporary password] for the next page only. */
    private const CREDENTIAL_FLASH = 'credential';

    public function __construct(
        private readonly UserAdministration $admin,
        private readonly UserChangeRequestRepository $requests,
    ) {
    }

    #[Route('/users', name: 'app_admin_users', methods: ['GET'])]
    public function index(UserRepository $users, AccountPolicy $policy): Response
    {
        return $this->render('admin/users.html.twig', [
            'users' => $users->findAllOrdered(),
            'pending' => $this->requests->findPending(),
            'policy' => $policy,
        ]);
    }

    // Not under /users/: "new" is a valid username.
    #[Route('/new-user', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = $this->createForm(NewAccountType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            try {
                $this->admin->request(UserChangeType::Create, $data['username'], $this->me(), $data['reason'], $data['displayName'], $data['role']);
                $this->addFlash('success', sprintf('Account "%s" requested. Another administrator has to approve it before it can be used.', mb_strtolower(trim($data['username']))));

                return $this->redirectToRoute('app_admin_users', status: Response::HTTP_SEE_OTHER);
            } catch (UserAdministrationException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/new.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/users/{username}', name: 'app_admin_user', methods: ['GET'])]
    public function show(#[MapEntity(mapping: ['username' => 'username'])] User $user, UserAuditEntryRepository $audit, AccountPolicy $policy): Response
    {
        return $this->render('admin/user.html.twig', [
            'user' => $user,
            'pending' => $this->requests->findPendingFor($user->getUsername()),
            'requests' => $this->requests->findFor($user->getUsername()),
            'audit' => $audit->findRecent($user->getUsername(), 100),
            'roles' => UserRole::cases(),
            'policy' => $policy,
        ]);
    }

    /** Requests a role change, disabling, enabling or a password reset of the account. */
    #[Route('/users/{username}/request', name: 'app_admin_user_request', methods: ['POST'])]
    #[IsCsrfTokenValid('user-admin')]
    #[IsGranted(UserAdminVoter::MANAGE, 'user')]
    public function requestChange(#[MapEntity(mapping: ['username' => 'username'])] User $user, Request $request): Response
    {
        $payload = $request->getPayload();
        $type = UserChangeType::tryFrom((string) $payload->get('type'));
        $role = UserRole::tryFrom((string) $payload->get('role'));

        try {
            if (null === $type || UserChangeType::Create === $type) {
                throw new UserAdministrationException('Unknown change.');
            }
            if (UserChangeType::ChangeRole === $type && null === $role) {
                throw new UserAdministrationException('Choose the new role.');
            }
            $change = $this->admin->request($type, $user->getUsername(), $this->me(), (string) $payload->get('reason'), role: UserChangeType::ChangeRole === $type ? $role : null);
            $this->addFlash('success', sprintf('%s requested. Another administrator has to approve it.', $change->describe()));
        } catch (UserAdministrationException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_user', ['username' => $user->getUsername()], Response::HTTP_SEE_OTHER);
    }

    /** Unlocking after failed sign-ins takes effect at once (it changes no access rights). */
    #[Route('/users/{username}/unlock', name: 'app_admin_user_unlock', methods: ['POST'])]
    #[IsCsrfTokenValid('user-admin')]
    #[IsGranted(UserAdminVoter::MANAGE, 'user')]
    public function unlock(#[MapEntity(mapping: ['username' => 'username'])] User $user): Response
    {
        try {
            $this->admin->unlock($user, $this->me());
            $this->addFlash('success', sprintf('Account "%s" unlocked.', $user->getUsername()));
        } catch (UserAdministrationException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_user', ['username' => $user->getUsername()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/requests/{id}/approve', name: 'app_admin_request_approve', methods: ['POST'])]
    #[IsCsrfTokenValid('user-admin')]
    #[IsGranted(UserAdminVoter::REVIEW, 'change')]
    public function approve(#[MapEntity(id: 'id')] UserChangeRequest $change, Request $request): Response
    {
        try {
            $password = $this->admin->approve($change, $this->me(), $this->note($request));
            $this->addFlash('success', sprintf('Approved: %s for "%s".', $change->describe(), $change->getTargetUsername()));
            if (null !== $password) {
                $this->addFlash(self::CREDENTIAL_FLASH, [$change->getTargetUsername(), $password]);
            }
        } catch (UserAdministrationException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_user', ['username' => $change->getTargetUsername()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/requests/{id}/reject', name: 'app_admin_request_reject', methods: ['POST'])]
    #[IsCsrfTokenValid('user-admin')]
    #[IsGranted(UserAdminVoter::REVIEW, 'change')]
    public function reject(#[MapEntity(id: 'id')] UserChangeRequest $change, Request $request): Response
    {
        try {
            $this->admin->reject($change, $this->me(), $this->note($request));
            $this->addFlash('success', sprintf('Rejected: %s for "%s".', $change->describe(), $change->getTargetUsername()));
        } catch (UserAdministrationException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_users', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/requests/{id}/cancel', name: 'app_admin_request_cancel', methods: ['POST'])]
    #[IsCsrfTokenValid('user-admin')]
    #[IsGranted(UserAdminVoter::CANCEL, 'change')]
    public function cancel(#[MapEntity(id: 'id')] UserChangeRequest $change): Response
    {
        $this->admin->cancel($change, $this->me());
        $this->addFlash('success', sprintf('Withdrawn: %s for "%s".', $change->describe(), $change->getTargetUsername()));

        return $this->redirectToRoute('app_admin_users', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/audit', name: 'app_admin_audit', methods: ['GET'])]
    public function audit(UserAuditEntryRepository $audit): Response
    {
        return $this->render('admin/audit.html.twig', [
            'entries' => $audit->findRecent(null, 300),
            'decided' => $this->requests->findDecided(),
        ]);
    }

    private function me(): string
    {
        return $this->getUser()->getUserIdentifier();
    }

    private function note(Request $request): ?string
    {
        $note = trim((string) $request->getPayload()->get('note'));

        return '' === $note ? null : mb_substr($note, 0, 1000);
    }
}
