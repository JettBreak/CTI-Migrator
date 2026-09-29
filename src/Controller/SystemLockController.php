<?php

namespace App\Controller;

use App\Entity\UserAuditEntry;
use App\Form\SystemLockType;
use App\Security\SuperUser;
use App\Security\SystemLock;
use App\Security\UserAudit;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Locking and unlocking the whole app with the super user's credentials, from the sign-in page.
 * Both pages are public (see access_control): the super user never signs in.
 */
#[Route('/system-lock')]
final class SystemLockController extends AbstractController
{
    public function __construct(
        private readonly SystemLock $lock,
        private readonly SuperUser $superUser,
        private readonly UserAudit $audit,
    ) {
    }

    #[Route('', name: 'app_system_lock', methods: ['GET', 'POST'])]
    public function lock(Request $request): Response
    {
        $status = $this->lock->status();
        if ($status->isLocked()) {
            return $this->redirectToRoute('app_system_unlock');
        }

        $form = $this->createForm(SystemLockType::class, null, ['purpose' => 'lock', 'scheduled' => $status->isScheduled()]);
        $form->handleRequest($request);
        $action = $form->has('action') ? $form->get('action')->getData() : null;
        $days = $form->get('days')->getData();
        if ($form->isSubmitted() && SystemLockType::LOCK_AFTER_DAYS === $action && (null === $days || $days < 1 || $days > SystemLockType::MAX_DAYS)) {
            $form->get('days')->addError(new FormError(null === $days
                ? 'Enter how many days the app can still be used.'
                : \sprintf('Choose between 1 and %d days.', SystemLockType::MAX_DAYS)));
        }

        if ($form->isSubmitted() && $form->isValid() && $this->credentialsAccepted($form, 'lock')) {
            $actor = $this->superUser->username();
            $reason = $form->get('reason')->getData();
            if (SystemLockType::CANCEL === $action) {
                $this->lock->clear();
                $this->audit->record($actor, UserAuditEntry::SYSTEM_LOCK_CANCELLED, 'system', $reason, flush: true);
                $this->addFlash('system_lock', 'The timed lock was cancelled.');
            } elseif (SystemLockType::LOCK_AFTER_DAYS === $action) {
                $locksAt = $this->lock->lockAfterDays($days, $actor, $reason);
                $this->audit->record($actor, UserAuditEntry::SYSTEM_LOCK_SCHEDULED, 'system', \sprintf('Locks on %s (%d days). %s', $locksAt->format('M j, Y H:i'), $days, $reason), flush: true);
                $this->addFlash('system_lock', \sprintf('The app can be used until %s, then it locks.', $locksAt->format('M j, Y H:i')));
            } else {
                $this->lock->lockNow($actor, $reason);
                $this->audit->record($actor, UserAuditEntry::SYSTEM_LOCKED, 'system', $reason, flush: true);
            }

            return $this->redirectToRoute('app_login', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('security/system_lock.html.twig', [
            'form' => $form,
            'status' => $status,
            'configured' => $this->superUser->isConfigured(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/unlock', name: 'app_system_unlock', methods: ['GET', 'POST'])]
    public function unlock(Request $request): Response
    {
        $status = $this->lock->status();
        if (!$status->isLocked()) {
            return $this->redirectToRoute('app_login');
        }

        $form = $this->createForm(SystemLockType::class, null, ['purpose' => 'unlock']);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->credentialsAccepted($form, 'unlock')) {
            $this->lock->clear();
            $this->audit->record($this->superUser->username(), UserAuditEntry::SYSTEM_UNLOCKED, 'system', $form->get('reason')->getData(), flush: true);
            $this->addFlash('system_lock', 'The system was unlocked. Sign-in is open again.');

            return $this->redirectToRoute('app_login', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('security/system_unlock.html.twig', [
            'form' => $form,
            'status' => $status,
            'configured' => $this->superUser->isConfigured(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function credentialsAccepted(FormInterface $form, string $purpose): bool
    {
        $result = $this->superUser->check($form->get('username')->getData(), $form->get('password')->getData(), $purpose);
        if (SuperUser::OK !== $result) {
            $form->addError(new FormError(SuperUser::THROTTLED === $result
                ? 'Too many attempts. Wait a few minutes before trying again.'
                : 'Invalid super user name or password.'));
        }

        return SuperUser::OK === $result;
    }
}
