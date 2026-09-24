<?php

namespace App\Migration;

use App\Core\CoreAccount;

/** A validated change: rename core account $account to $newAccountNo. */
final readonly class RenamePlan
{
    public function __construct(
        public CoreAccount $account,
        public string $newAccountNo,
    ) {
    }
}
