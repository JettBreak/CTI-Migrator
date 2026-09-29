<?php

namespace App\Twig;

use App\Enum\LoadingAnimation;
use App\Service\AppSettings;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Attribute\AsTwigFunction;

/** loading_animation(): the animation every loading indicator shows (templates/_loading.html.twig). */
final class SettingsExtension implements ResetInterface
{
    private ?LoadingAnimation $animation = null; // read once per request: a page has several indicators

    public function __construct(private readonly AppSettings $settings)
    {
    }

    #[AsTwigFunction('loading_animation')]
    public function loadingAnimation(): LoadingAnimation
    {
        return $this->animation ??= $this->settings->loadingAnimation();
    }

    public function reset(): void
    {
        $this->animation = null;
    }
}
