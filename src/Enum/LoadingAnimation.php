<?php

namespace App\Enum;

/** The animation in the loading indicator (templates/_loading.html.twig), set for everyone in admin settings. */
enum LoadingAnimation: string
{
    case Rocket = 'rocket';
    case Dinosaur = 'dinosaur';

    public function label(): string
    {
        return match ($this) {
            self::Rocket => 'Rocket',
            self::Dinosaur => 'Dinosaur',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Rocket => 'A pixel rocket cruising through streaking stars, lifting over an asteroid.',
            self::Dinosaur => 'A pixel dinosaur running over the ground, jumping a cactus.',
        };
    }
}
