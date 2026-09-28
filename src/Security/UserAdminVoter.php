<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\UserChangeRequest;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Maker-checker between user administrators: an administrator manages other people's accounts
 * (never their own), and approves or rejects only requests someone else made, about someone else.
 * UserAdministration checks the business rules (pending requests, last administrator, …).
 *
 * @extends Voter<string, User|UserChangeRequest>
 */
final class UserAdminVoter extends Voter
{
    /** Request changes to this account, or unlock it. */
    public const MANAGE = 'USER_MANAGE';
    public const REVIEW = 'USER_CHANGE_REVIEW';
    public const CANCEL = 'USER_CHANGE_CANCEL';

    public function __construct(private readonly AccessDecisionManagerInterface $decisions)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return (self::MANAGE === $attribute && $subject instanceof User)
            || (\in_array($attribute, [self::REVIEW, self::CANCEL], true) && $subject instanceof UserChangeRequest);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $me = $token->getUser()?->getUserIdentifier();
        if (null === $me || !$this->decisions->decide($token, ['ROLE_USER_ADMIN'])) {
            return false;
        }

        if (self::MANAGE === $attribute) {
            $vote?->addReason('Administrators cannot change their own account.');

            return $subject->getUsername() !== $me;
        }

        if (self::CANCEL === $attribute) {
            $vote?->addReason('Only the administrator who asked for a pending change can withdraw it.');

            return $subject->isPending() && $subject->getRequestedBy() === $me;
        }

        $vote?->addReason('A different administrator approves or rejects a change, and never one to their own account.');

        return $subject->isPending() && $subject->getRequestedBy() !== $me && $subject->getTargetUsername() !== $me;
    }
}
