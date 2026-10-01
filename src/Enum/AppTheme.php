<?php

namespace App\Enum;

/**
 * The look of every page, including the sign-in page, set for everyone in admin settings. The layouts put it on
 * <html data-app-theme>, for app.css to key a theme's styles on. Separate from the light/dark switch in the header,
 * which each user sets in their browser (<html data-theme>): a theme comes in both.
 */
enum AppTheme: string
{
    case Space = 'space';

    public function label(): string
    {
        return match ($this) {
            self::Space => 'Space',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Space => 'Deep blue night sky with stars, a turning globe and the solar system on the sign-in page.',
        };
    }
}
