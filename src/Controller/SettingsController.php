<?php

namespace App\Controller;

use App\Entity\UserAuditEntry;
use App\Enum\LoadingAnimation;
use App\Form\AppSettingsType;
use App\Security\UserAudit;
use App\Service\AppSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administration > Settings: app-wide settings that apply to everyone (App\Service\AppSettings), such as
 * the loading animation. Each change is recorded in the account audit trail.
 */
#[IsGranted('ROLE_USER_ADMIN')]
final class SettingsController extends AbstractController
{
    #[Route('/admin/settings', name: 'app_admin_settings', methods: ['GET', 'POST'])]
    public function index(Request $request, AppSettings $settings, UserAudit $audit): Response
    {
        $current = $settings->loadingAnimation();
        $form = $this->createForm(AppSettingsType::class, ['loadingAnimation' => $current]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var LoadingAnimation $chosen */
            $chosen = $form->get('loadingAnimation')->getData();
            if ($chosen === $current) {
                $this->addFlash('success', \sprintf('The loading animation is already the %s.', mb_strtolower($chosen->label())));
            } else {
                $settings->setLoadingAnimation($chosen);
                $audit->record($this->getUser()->getUserIdentifier(), UserAuditEntry::SETTING_CHANGED, 'settings',
                    \sprintf('Loading animation: %s (was %s)', $chosen->label(), $current->label()), flush: true);
                $this->addFlash('success', \sprintf('Everyone now sees the %s while pages load.', mb_strtolower($chosen->label())));
            }

            return $this->redirectToRoute('app_admin_settings');
        }

        return $this->render('admin/settings.html.twig', ['form' => $form, 'current' => $current, 'animations' => LoadingAnimation::cases()]);
    }
}
