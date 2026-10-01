<?php

namespace App\Service;

use App\Enum\AppTheme;
use App\Enum\LoadingAnimation;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * App-wide settings chosen by user administrators (App\Controller\SettingsController), for everyone.
 *
 * Kept in a small JSON file on the server, like the system lock, so they apply on every page including
 * the sign-in page, and need no database change. A missing or unreadable file, or an unknown value,
 * means the default. Writes replace the file in one step, so a reader never sees half of it.
 */
final class AppSettings
{
    public function __construct(
        #[Autowire('%app.settings.file%')] private readonly string $file,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function loadingAnimation(): LoadingAnimation
    {
        return LoadingAnimation::tryFrom((string) ($this->read()['loading_animation'] ?? '')) ?? LoadingAnimation::Rocket;
    }

    public function setLoadingAnimation(LoadingAnimation $animation): void
    {
        $this->write(['loading_animation' => $animation->value] + $this->read());
    }

    public function theme(): AppTheme
    {
        return AppTheme::tryFrom((string) ($this->read()['theme'] ?? '')) ?? AppTheme::Space;
    }

    public function setTheme(AppTheme $theme): void
    {
        $this->write(['theme' => $theme->value] + $this->read());
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $content = is_file($this->file) ? @file_get_contents($this->file) : false;
        $settings = false === $content ? null : json_decode($content, true);

        return \is_array($settings) ? $settings : [];
    }

    /** @param array<string, mixed> $settings */
    private function write(array $settings): void
    {
        $this->filesystem->dumpFile($this->file, json_encode($settings, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n");
    }
}
