<?php

namespace App\Security;

use App\Entity\MigrationBatch;
use App\Enum\BatchStatus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Maker-checker: officers upload batches (approvers never do: they only check), the officer who
 * uploaded a batch submits it (or, if some rows need correction, submits its valid rows only), and a different user with ROLE_MIGRATION_APPROVER approves or
 * rejects it, and resumes it if applying stopped part-way.
 *
 * @extends Voter<string, MigrationBatch|null>
 */
final class BatchVoter extends Voter
{
    /** No subject: may this user upload a new batch? */
    public const UPLOAD = 'BATCH_UPLOAD';
    public const SUBMIT = 'BATCH_SUBMIT';
    /** Submit a batch that needs correction without its rows to correct. */
    public const SUBMIT_VALID = 'BATCH_SUBMIT_VALID';
    public const REVIEW = 'BATCH_REVIEW';
    public const RESUME = 'BATCH_RESUME';

    public function __construct(private readonly AccessDecisionManagerInterface $decisions)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (self::UPLOAD === $attribute) {
            return null === $subject;
        }

        return in_array($attribute, [self::SUBMIT, self::SUBMIT_VALID, self::REVIEW, self::RESUME], true) && $subject instanceof MigrationBatch;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser()?->getUserIdentifier();
        if (null === $user) {
            return false;
        }

        if (self::UPLOAD === $attribute) {
            // Approvers inherit ROLE_MIGRATION_OFFICER (role_hierarchy), so check for the approver role itself.
            $vote?->addReason('Approvers review batches; migration officers upload them.');

            return $this->decisions->decide($token, ['ROLE_MIGRATION_OFFICER']) && !$this->decisions->decide($token, ['ROLE_MIGRATION_APPROVER']);
        }

        if (self::SUBMIT === $attribute) {
            $vote?->addReason('Only the uploader can submit a validated batch.');

            return BatchStatus::Validated === $subject->getStatus() && $subject->getUploadedBy() === $user;
        }

        if (self::SUBMIT_VALID === $attribute) {
            $vote?->addReason('Only the uploader can submit the valid rows of a batch that needs correction, and it must have valid rows.');

            return BatchStatus::Invalid === $subject->getStatus() && $subject->validRowCount() > 0 && $subject->getUploadedBy() === $user;
        }

        [$status, $reason] = self::RESUME === $attribute
            ? [BatchStatus::Halted, 'An approver other than the uploader must resume a halted batch.']
            : [BatchStatus::AwaitingApproval, 'An approver other than the uploader must review a submitted batch.'];
        $vote?->addReason($reason);

        return $status === $subject->getStatus()
            && $subject->getUploadedBy() !== $user
            && $this->decisions->decide($token, ['ROLE_MIGRATION_APPROVER']);
    }
}
