<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserAuditEntry;
use App\Form\ChangePasswordType;
use App\Security\AccountPolicy;
use App\Security\PasswordManager;
use App\Security\UserAudit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The signed-in user's own account: where to land after signing in, and changing the password. */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/account')]
final class AccountController extends AbstractController
{
    /** After signing in: administrators go to the user administration, everyone else to the overview. */
    #[Route('/start', name: 'app_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->redirectToRoute($this->isGranted('ROLE_USER_ADMIN') ? 'app_admin_users' : 'app_dashboard');
    }

    #[Route('/password', name: 'app_account_password', methods: ['GET', 'POST'])]
    public function password(
        Request $request,
        #[CurrentUser] User $user,
        PasswordManager $passwords,
        AccountPolicy $policy,
        UserAudit $audit,
        EntityManagerInterface $em,
    ): Response {
        $form = $this->createForm(ChangePasswordType::class, null, ['user' => $user]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $passwords->change($user, $form->get('newPassword')->getData(), false);
            $audit->record($user->getUsername(), UserAuditEntry::PASSWORD_CHANGED, $user->getUsername());
            $em->flush();
            $request->getSession()->migrate(true);
            $this->addFlash('success', 'Your password was changed.');

            return $this->redirectToRoute('app_home', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('account/password.html.twig', [
            'form' => $form,
            'forced' => $user->mustChangePassword(),
            'expired' => !$user->mustChangePassword() && $policy->passwordExpired($user),
            'max_age' => $policy->passwordMaxAge,
            'history' => $policy->passwordHistory,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
