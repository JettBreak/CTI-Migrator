<?php

namespace App\Core;

/** A card record (prmaster, prtype CARD) with its account links. The PAN is already masked. */
final readonly class CoreCard
{
    /** @param list<CoreLink> $links */
    public function __construct(
        public int $seq,
        public int $customerSeq,
        public string $maskedCard,
        public string $cardholder,
        public array $links,
    ) {
    }

    public function isLinkedTo(string $accountNo): bool
    {
        foreach ($this->links as $link) {
            if ($link->accountNo === $accountNo) {
                return true;
            }
        }

        return false;
    }
}
