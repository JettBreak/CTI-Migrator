<?php

namespace App\Core;

/** A prlinkxx row pointing at an account, seen from the account side. */
final readonly class CoreAccountLink
{
    public function __construct(
        public int $cardSeq,
        public string $xml,
    ) {
    }
}
