<?php

namespace App\Twig;

use App\Enum\AppTheme;
use App\Enum\LoadingAnimation;
use App\Service\AppSettings;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * loading_animation(): the animation every loading indicator shows (templates/_loading.html.twig): the one chosen in
 * settings, or the theme's own where the theme does not show that one.
 * app_theme(): the theme every page is drawn in (<html data-app-theme> in the layouts).
 */
final class SettingsExtension implements ResetInterface
{
    private ?LoadingAnimation $animation = null; // read once per request: a page has several indicators
    private ?AppTheme $theme = null;

    public function __construct(private readonly AppSettings $settings)
    {
    }

    #[AsTwigFunction('loading_animation')]
    public function loadingAnimation(): LoadingAnimation
    {
        if (null === $this->animation) {
            $theme = $this->theme();
            $chosen = $this->settings->loadingAnimation();
            $this->animation = $theme->allows($chosen) ? $chosen : $theme->defaultLoadingAnimation();
        }

        return $this->animation;
    }

    #[AsTwigFunction('app_theme')]
    public function theme(): AppTheme
    {
        return $this->theme ??= $this->settings->theme();
    }

    public function reset(): void
    {
        $this->animation = null;
        $this->theme = null;
    }
}
