<?php

namespace App\Security;

use App\Entity\MigrationBatch;
use App\Enum\BatchStatus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Maker-checker: the officer who uploaded a batch submits it; a different user with
 * ROLE_MIGRATION_APPROVER approves or rejects it.
 *
 * @extends Voter<string, MigrationBatch>
 */
final class BatchVoter extends Voter
{
    public const SUBMIT = 'BATCH_SUBMIT';
    public const REVIEW = 'BATCH_REVIEW';

    public function __construct(private readonly AccessDecisionManagerInterface $decisions)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::SUBMIT, self::REVIEW], true) && $subject instanceof MigrationBatch;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser()?->getUserIdentifier();
        if (null === $user) {
            return false;
        }

        if (self::SUBMIT === $attribute) {
            $vote?->addReason('Only the uploader can submit a validated batch.');

            return BatchStatus::Validated === $subject->getStatus() && $subject->getUploadedBy() === $user;
        }

        $vote?->addReason('An approver other than the uploader must review a submitted batch.');

        return BatchStatus::AwaitingApproval === $subject->getStatus()
            && $subject->getUploadedBy() !== $user
            && $this->decisions->decide($token, ['ROLE_MIGRATION_APPROVER']);
    }
}
