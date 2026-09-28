<?php

namespace App\Validator;

use App\Entity\User;
use Symfony\Component\Validator\Constraint;

/**
 * The password rules: 12 to 128 characters with upper case, lower case, a digit and a symbol;
 * not containing the username; and (given the account) none of its last few passwords.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class PasswordPolicy extends Constraint
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 128;

    public function __construct(
        /** The account the password is for; its username and password history are checked. */
        public readonly ?User $user = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
