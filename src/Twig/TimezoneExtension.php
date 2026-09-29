<?php

namespace App\Twig;

use Twig\Attribute\AsTwigFunction;

/**
 * app_timezone_location(): where the app's timezone (APP_TIMEZONE, e.g. Asia/Manila) is on the map, from the
 * timezone database, and its city's name ("Manila"), for the marker on the sign-in page's globe. Null for a
 * zone with no place, such as UTC.
 */
final class TimezoneExtension
{
    /** @return array{lon: float, lat: float, city: string}|null */
    #[AsTwigFunction('app_timezone_location')]
    public function location(): ?array
    {
        $zone = date_default_timezone_get();
        $location = (new \DateTimeZone($zone))->getLocation();
        if (false === $location || '??' === $location['country_code']) {
            return null;
        }

        return [
            'lon' => round($location['longitude'], 2),
            'lat' => round($location['latitude'], 2),
            // America/Argentina/Buenos_Aires → Buenos Aires
            'city' => str_replace('_', ' ', substr($zone, strrpos($zone, '/') + 1)),
        ];
    }
}
