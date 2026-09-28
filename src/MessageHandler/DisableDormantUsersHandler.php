<?php

namespace App\MessageHandler;

use App\Entity\UserAuditEntry;
use App\Enum\UserRole;
use App\Message\DisableDormantUsers;
use App\Repository\UserRepository;
use App\Security\AccountPolicy;
use App\Security\UserAudit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Disables accounts with no sign-in (nor creation or re-enabling) for app.security.dormant_after.
 * Sign-in refuses them anyway (UserChecker); this makes it visible and final until re-enabled.
 * The last active user administrator is left alone, so accounts can still be managed.
 */
#[AsMessageHandler]
final class DisableDormantUsersHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AccountPolicy $policy,
        private readonly UserAudit $audit,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return int accounts disabled */
    public function __invoke(DisableDormantUsers $message): int
    {
        $disabled = 0;
        $admins = $this->users->countActiveWithRole(UserRole::Admin);
        foreach ($this->users->findDormant($this->policy->dormantBefore()) as $user) {
            if (UserRole::Admin === $user->getRole() && $admins - 1 < 1) {
                continue;
            }
            $admins -= UserRole::Admin === $user->getRole() ? 1 : 0;
            $user->disable('system', $this->policy->now());
            $this->audit->record('system', UserAuditEntry::DISABLED, $user->getUsername(), sprintf('Not used for %s.', $this->policy->dormantAfter));
            ++$disabled;
        }
        $this->em->flush();

        return $disabled;
    }
}
