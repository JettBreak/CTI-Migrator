<?php

namespace App\Enum;

/**
 * The look of every page, including the sign-in page, set for everyone in admin settings. The layouts put it on
 * <html data-app-theme>, for app.css to key a theme's styles on. Separate from the light/dark switch in the header,
 * which each user sets in their browser (<html data-theme>): a theme comes in both.
 *
 * stylesheet() is the theme's own stylesheet, loaded after app.css on every page; null for none.
 * scenery() is what the pages are dressed in:
 *   - "space": starfields in the sidebar and behind dark pages, the globe and solar system on the sign-in page,
 *     the rocket swipe on signing in and out;
 *   - "city": a pixel city skyline on the sign-in page (pixel-city controller), a pixel block dissolve on signing
 *     in and out, and no starfields;
 *   - "terminal": a terminal window typing a boot log on the sign-in page (boot-log controller), the screen clearing
 *     line by line on signing in and out, and no starfields;
 *   - "plain": a plain page with an illustration beside the sign-in form, a plain fade on signing in and out, and
 *     no starfields.
 */
enum AppTheme: string
{
    case Space = 'space';
    case EightBit = '8bit';
    case Developer = 'developer';
    case Corporate = 'corporate';

    public function label(): string
    {
        return match ($this) {
            self::Space => 'Space',
            self::EightBit => '8-bit console',
            self::Developer => 'Developer console',
            self::Corporate => 'Corporate',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Space => 'Deep blue night sky with stars, a turning globe and the solar system on the sign-in page.',
            self::EightBit => 'Classic console look for the office: bold primaries, black outlines and pixel fonts, with a pixel city skyline on the sign-in page.',
            self::Developer => 'A terminal and code editor look: monospace type, syntax-highlight colours and thin panes, with a boot log typing on the sign-in page.',
            self::Corporate => 'A classic banking portal: navy sidebar, clean white pages and one strong blue for actions, with a plain, quiet sign-in page.',
        };
    }

    public function stylesheet(): ?string
    {
        return match ($this) {
            self::Space => null,
            self::EightBit => 'styles/theme-8bit.css',
            self::Developer => 'styles/theme-developer.css',
            self::Corporate => 'styles/theme-corporate.css',
        };
    }

    /** The web fonts the theme's stylesheet uses (Google Fonts), on top of the app's own; null for none. */
    public function fonts(): ?string
    {
        return match ($this) {
            self::Space => null,
            self::EightBit => 'https://fonts.googleapis.com/css2?family=Press+Start+2P&family=Pixelify+Sans:wght@400;500;600;700&display=swap',
            self::Developer => 'https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&display=swap',
            self::Corporate => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
        };
    }

    public function scenery(): string
    {
        return match ($this) {
            self::Space => 'space',
            self::EightBit => 'city',
            self::Developer => 'terminal',
            self::Corporate => 'plain',
        };
    }

    /**
     * The Stimulus controller that draws the scenery behind the signed-out pages; the page loader waits for its
     * "<controller>:ready" event. Null for a still scene, with nothing to wait for.
     */
    public function sceneController(): ?string
    {
        return match ($this->scenery()) {
            'space' => 'login-globe',
            'city' => 'pixel-city',
            'terminal' => 'boot-log',
            'plain' => null,
        };
    }

    /**
     * How the loading overlay goes away right after signing in or out (assets/rocket_swipe.js); "fade" is the
     * overlay's own fade-out, with nothing more.
     */
    public function transition(): string
    {
        return match ($this->scenery()) {
            'space' => 'rocket',
            'city' => 'blocks',
            'terminal' => 'lines',
            'plain' => 'fade',
        };
    }

    /** Whether the theme shows $animation; the rocket belongs to space. */
    public function allows(LoadingAnimation $animation): bool
    {
        return LoadingAnimation::Rocket !== $animation || 'space' === $this->scenery();
    }

    /** What the theme shows in place of a loading animation it does not allow. */
    public function defaultLoadingAnimation(): LoadingAnimation
    {
        return match ($this) {
            self::Space => LoadingAnimation::Rocket,
            self::EightBit => LoadingAnimation::NowLoading,
            self::Developer => LoadingAnimation::Terminal,
            self::Corporate => LoadingAnimation::Spinner,
        };
    }
}
