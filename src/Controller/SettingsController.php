<?php

namespace App\Controller;

use App\Entity\UserAuditEntry;
use App\Enum\AppTheme;
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
 * Administration > Settings: app-wide settings that apply to everyone (App\Service\AppSettings): the theme
 * and the loading animation. Each change is recorded in the account audit trail.
 */
#[IsGranted('ROLE_USER_ADMIN')]
final class SettingsController extends AbstractController
{
    #[Route('/admin/settings', name: 'app_admin_settings', methods: ['GET', 'POST'])]
    public function index(Request $request, AppSettings $settings, UserAudit $audit): Response
    {
        $theme = $settings->theme();
        $animation = $settings->loadingAnimation();
        $form = $this->createForm(AppSettingsType::class, ['theme' => $theme, 'loadingAnimation' => $animation]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $actor = $this->getUser()->getUserIdentifier();
            $changed = false;

            /** @var AppTheme $chosenTheme */
            $chosenTheme = $form->get('theme')->getData();
            if ($chosenTheme !== $theme) {
                $settings->setTheme($chosenTheme);
                $audit->record($actor, UserAuditEntry::SETTING_CHANGED, 'settings',
                    \sprintf('Theme: %s (was %s)', $chosenTheme->label(), $theme->label()), flush: true);
                $this->addFlash('success', \sprintf('Everyone now sees the %s theme.', $chosenTheme->label()));
                $changed = true;
            }

            /** @var LoadingAnimation $chosenAnimation */
            $chosenAnimation = $form->get('loadingAnimation')->getData();
            if ($chosenAnimation !== $animation) {
                $settings->setLoadingAnimation($chosenAnimation);
                $audit->record($actor, UserAuditEntry::SETTING_CHANGED, 'settings',
                    \sprintf('Loading animation: %s (was %s)', $chosenAnimation->label(), $animation->label()), flush: true);
                $this->addFlash('success', \sprintf('Everyone now sees the %s while pages load.', mb_strtolower($chosenAnimation->label())));
                $changed = true;
            }

            if (!$changed) {
                $this->addFlash('success', 'Nothing changed: these settings are already in use.');
            }

            return $this->redirectToRoute('app_admin_settings');
        }

        return $this->render('admin/settings.html.twig', [
            'form' => $form,
            'current' => $animation,
            'animations' => LoadingAnimation::cases(),
            'currentTheme' => $theme,
            'themes' => AppTheme::cases(),
        ]);
    }
}
