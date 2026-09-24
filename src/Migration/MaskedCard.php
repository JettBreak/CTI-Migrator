<?php

namespace App\Migration;

final class MaskedCard
{
    /**
     * Turns a raw masked PAN ("541286******8821") into its display form ("5412 86•• •••• 8821").
     */
    public static function format(string $masked): string
    {
        return str_replace('*', '•', implode(' ', str_split($masked, 4)));
    }
}
