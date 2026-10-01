<?php

namespace App\Enum;

/**
 * The animation in the loading indicator (templates/_loading.html.twig), set for everyone in admin settings. A theme
 * may not show every one (AppTheme::allows()): it then shows its own default instead.
 */
enum LoadingAnimation: string
{
    case Rocket = 'rocket';
    case Dinosaur = 'dinosaur';
    case NowLoading = 'now-loading';
    case Terminal = 'terminal';
    case Spinner = 'spinner';

    public function label(): string
    {
        return match ($this) {
            self::Rocket => 'Rocket',
            self::Dinosaur => 'Dinosaur',
            self::NowLoading => 'Now loading',
            self::Terminal => 'Terminal',
            self::Spinner => 'Spinner',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Rocket => 'A pixel rocket cruising through streaking stars, lifting over an asteroid.',
            self::Dinosaur => 'A pixel dinosaur running over the ground, jumping a cactus.',
            self::NowLoading => 'A blinking NOW LOADING over a block bar filling up, like an old game console.',
            self::Terminal => 'A command-line spinner and a [#####.....] progress bar counting up to 100%.',
            self::Spinner => 'A plain turning ring: quiet and businesslike.',
        };
    }
}
