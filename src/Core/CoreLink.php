<?php

namespace App\Core;

/** A card's link (prlinkxx row) to an account, seen from the card side. */
final readonly class CoreLink
{
    public function __construct(
        public int $accountSeq,
        public string $accountNo,
        public ?string $accountType,
        public string $xml,
    ) {
    }
}
