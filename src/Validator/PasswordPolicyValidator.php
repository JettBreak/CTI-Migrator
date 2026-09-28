<?php

namespace App\Validator;

use App\Security\AccountPolicy;
use App\Security\PasswordManager;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class PasswordPolicyValidator extends ConstraintValidator
{
    public function __construct(
        private readonly PasswordManager $passwords,
        private readonly AccountPolicy $policy,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PasswordPolicy) {
            throw new UnexpectedTypeException($constraint, PasswordPolicy::class);
        }
        if (null === $value || '' === $value) {
            return;
        }

        $length = mb_strlen($value);
        if ($length < PasswordPolicy::MIN_LENGTH || $length > PasswordPolicy::MAX_LENGTH) {
            $this->context->addViolation(sprintf('Use %d to %d characters.', PasswordPolicy::MIN_LENGTH, PasswordPolicy::MAX_LENGTH));
        }

        $missing = array_keys(array_filter([
            'an upper-case letter' => !preg_match('/\p{Lu}/u', $value),
            'a lower-case letter' => !preg_match('/\p{Ll}/u', $value),
            'a digit' => !preg_match('/\d/', $value),
            'a symbol' => !preg_match('/[^\p{L}\d]/u', $value),
        ]));
        if ($missing) {
            $this->context->addViolation('Include '.self::sentence($missing).'.');
        }

        $user = $constraint->user;
        if (null === $user) {
            return;
        }
        if (str_contains(mb_strtolower($value), mb_strtolower($user->getUsername()))) {
            $this->context->addViolation('The password cannot contain your username.');
        }
        if ($this->passwords->wasUsedBefore($user, $value)) {
            $this->context->addViolation(sprintf('Choose a password you have not used for your last %d passwords.', $this->policy->passwordHistory));
        }
    }

    /** @param list<string> $items */
    private static function sentence(array $items): string
    {
        $last = array_pop($items);

        return $items ? implode(', ', $items).' and '.$last : $last;
    }
}
