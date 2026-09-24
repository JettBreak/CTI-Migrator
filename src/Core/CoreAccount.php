<?php

namespace App\Core;

/** An account record (prmaster, prtype ACCT) and the card links (prlinkxx) pointing at it. */
final readonly class CoreAccount
{
    /** @param list<CoreAccountLink> $links */
    public function __construct(
        public int $seq,
        public string $accountNo,
        public int $customerSeq,
        public ?string $accountType,
        public int $branchSeq,
        public array $links,
    ) {
    }
}
