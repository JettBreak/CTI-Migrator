<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\UserAuditEntry;
use App\Entity\UserChangeRequest;
use App\Enum\UserChangeStatus;
use App\Enum\UserChangeType;
use App\Enum\UserRole;
use App\Repository\UserChangeRequestRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Account administration, maker-checker: request() records a change one administrator wants, and
 * approve() (by a different administrator) carries it out. Unlocking an account after failed
 * sign-ins takes effect at once. The *Directly() methods are for the console commands run on the
 * server (setting up the first administrators, and recovery).
 *
 * Rules, checked when requesting and again when approving: administrators never change their own
 * account; at most one request per account is pending; and the last active administrator cannot be
 * disabled or given another role.
 */
final class UserAdministration
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserChangeRequestRepository $requests,
        private readonly PasswordManager $passwords,
        private readonly AccountPolicy $policy,
        private readonly UserAudit $audit,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function request(UserChangeType $type, string $target, string $by, string $reason, ?string $displayName = null, ?UserRole $role = null): UserChangeRequest
    {
        $target = mb_strtolower(trim($target));
        $reason = trim($reason);
        if ('' === $reason) {
            throw new UserAdministrationException('Give a reason for the change.');
        }
        if (null !== $this->requests->findPendingFor($target)) {
            throw new UserAdministrationException(sprintf('A change to "%s" is already waiting for approval.', $target));
        }
        if (UserChangeType::Create === $type) {
            $displayName = trim((string) $displayName);
            if ('' === $displayName || null === $role) {
                throw new UserAdministrationException('A new account needs a name and a role.');
            }
        }
        $this->check($type, $target, $by, $role);

        $request = new UserChangeRequest($type, $target, $by, mb_substr($reason, 0, 1000), $this->policy->now(), $displayName, $role);
        $this->em->persist($request);
        $this->audit->record($by, UserAuditEntry::CHANGE_REQUESTED, $target, $request->describe().'. Reason: '.$request->getReason());
        $this->em->flush();

        return $request;
    }

    /**
     * Carries out the request.
     *
     * @return string|null the temporary password of a new or reset account, to hand to its owner
     */
    public function approve(UserChangeRequest $request, string $by, ?string $note = null): ?string
    {
        $this->assertReviewer($request, $by);
        $this->check($request->getType(), $request->getTargetUsername(), $request->getRequestedBy(), $request->getRole());
        $this->check($request->getType(), $request->getTargetUsername(), $by, $request->getRole());

        $request->decide(UserChangeStatus::Approved, $by, $note, $this->policy->now());
        $this->audit->record($by, UserAuditEntry::CHANGE_APPROVED, $request->getTargetUsername(), $request->describe().' (requested by '.$request->getRequestedBy().')'.($note ? '. Note: '.$note : ''));
        $password = $this->apply($request->getType(), $request->getTargetUsername(), $by, $request->getDisplayName(), $request->getRole(), createdBy: $request->getRequestedBy());
        $this->em->flush();

        return $password;
    }

    public function reject(UserChangeRequest $request, string $by, ?string $note = null): void
    {
        $this->assertReviewer($request, $by);
        $request->decide(UserChangeStatus::Rejected, $by, $note, $this->policy->now());
        $this->audit->record($by, UserAuditEntry::CHANGE_REJECTED, $request->getTargetUsername(), $request->describe().' (requested by '.$request->getRequestedBy().')'.($note ? '. Note: '.$note : ''));
        $this->em->flush();
    }

    public function cancel(UserChangeRequest $request, string $by): void
    {
        if ($request->getRequestedBy() !== $by) {
            throw new UserAdministrationException('Only the administrator who asked for a change can withdraw it.');
        }
        $request->decide(UserChangeStatus::Cancelled, $by, null, $this->policy->now());
        $this->audit->record($by, UserAuditEntry::CHANGE_CANCELLED, $request->getTargetUsername(), $request->describe());
        $this->em->flush();
    }

    public function unlock(User $user, string $by): void
    {
        if ($user->getUsername() === $by) {
            throw new UserAdministrationException('Another administrator has to unlock your account.');
        }
        if (!$user->isLocked()) {
            throw new UserAdministrationException('This account is not locked.');
        }
        $user->unlock();
        $this->audit->record($by, UserAuditEntry::UNLOCKED, $user->getUsername());
        $this->em->flush();
    }

    /**
     * Creates an account at once (console only).
     *
     * @return string the temporary password when none was given
     */
    public function createDirectly(string $username, string $displayName, UserRole $role, string $by, #[\SensitiveParameter] ?string $password = null): string
    {
        $username = mb_strtolower(trim($username));
        $this->check(UserChangeType::Create, $username, $by, $role);
        $temporary = $this->apply(UserChangeType::Create, $username, $by, trim($displayName), $role, $password);
        $this->em->flush();

        return $password ?? $temporary;
    }

    /** Resets a password at once (console only); returns the temporary password. */
    public function resetPasswordDirectly(User $user, string $by): string
    {
        $password = $this->apply(UserChangeType::ResetPassword, $user->getUsername(), $by);
        $this->em->flush();

        return $password;
    }

    public function setActiveDirectly(User $user, bool $active, string $by): void
    {
        $this->check($active ? UserChangeType::Enable : UserChangeType::Disable, $user->getUsername(), $by, null);
        $this->apply($active ? UserChangeType::Enable : UserChangeType::Disable, $user->getUsername(), $by);
        $this->em->flush();
    }

    public function unlockDirectly(User $user, string $by): void
    {
        $user->unlock();
        $this->audit->record($by, UserAuditEntry::UNLOCKED, $user->getUsername());
        $this->em->flush();
    }

    /** Whether the change is allowed now; throws with the reason when it is not. */
    private function check(UserChangeType $type, string $target, string $by, ?UserRole $role): void
    {
        if ($target === $by) {
            throw new UserAdministrationException('Administrators cannot change their own account; another administrator has to.');
        }

        $user = $this->users->findOneByUsername($target);
        if (UserChangeType::Create === $type) {
            if (!preg_match(User::USERNAME_PATTERN, $target)) {
                throw new UserAdministrationException('Usernames are 3 to 50 characters: lower-case letters, digits, dots, dashes and underscores, starting with a letter or digit.');
            }
            if (null !== $user) {
                throw new UserAdministrationException(sprintf('The username "%s" is taken (accounts are never deleted, so it cannot be reused).', $target));
            }

            return;
        }

        if (null === $user) {
            throw new UserAdministrationException(sprintf('There is no account "%s".', $target));
        }

        match ($type) {
            UserChangeType::ChangeRole => $role === $user->getRole() ? throw new UserAdministrationException('The account already has this role.') : null,
            UserChangeType::Disable => $user->isActive() ? null : throw new UserAdministrationException('The account is already disabled.'),
            UserChangeType::Enable => $user->isActive() ? throw new UserAdministrationException('The account is already active.') : null,
            UserChangeType::ResetPassword => $user->isActive() ? null : throw new UserAdministrationException('Enable the account before resetting its password.'),
            default => null,
        };

        $removesAdmin = UserRole::Admin === $user->getRole() && $user->isActive()
            && (UserChangeType::Disable === $type || UserChangeType::ChangeRole === $type);
        if ($removesAdmin && $this->users->countActiveWithRole(UserRole::Admin) <= 1) {
            throw new UserAdministrationException('This is the last active user administrator; add another one first.');
        }
    }

    private function assertReviewer(UserChangeRequest $request, string $by): void
    {
        if (!$request->isPending()) {
            throw new UserAdministrationException('This request was already decided.');
        }
        if ($request->getRequestedBy() === $by) {
            throw new UserAdministrationException('A different administrator has to approve or reject your request.');
        }
    }

    /**
     * @param string|null $createdBy who asked for a new account, when not $by (who approved it)
     *
     * @return string|null the temporary password of a new or reset account
     */
    private function apply(UserChangeType $type, string $target, string $by, ?string $displayName = null, ?UserRole $role = null, #[\SensitiveParameter] ?string $password = null, ?string $createdBy = null): ?string
    {
        $now = $this->policy->now();

        if (UserChangeType::Create === $type) {
            $user = new User($target, (string) $displayName, $role, $createdBy ?? $by, $now);
            $temporary = $password ?? $this->passwords->temporary();
            $this->passwords->change($user, $temporary, true);
            $this->em->persist($user);
            $this->audit->record($by, UserAuditEntry::CREATED, $target, sprintf('%s, %s.', $displayName, $role->label()));

            return $temporary;
        }

        $user = $this->users->findOneByUsername($target);
        switch ($type) {
            case UserChangeType::ChangeRole:
                $this->audit->record($by, UserAuditEntry::ROLE_CHANGED, $target, sprintf('%s → %s.', $user->getRole()->label(), $role->label()));
                $user->changeRole($role);

                return null;
            case UserChangeType::Disable:
                $user->disable($by, $now);
                $this->audit->record($by, UserAuditEntry::DISABLED, $target);

                return null;
            case UserChangeType::Enable:
                $user->enable($now);
                $this->audit->record($by, UserAuditEntry::ENABLED, $target);

                return null;
            case UserChangeType::ResetPassword:
                $temporary = $this->passwords->temporary();
                $this->passwords->change($user, $temporary, true);
                $user->unlock();
                $user->endSessions();
                $this->audit->record($by, UserAuditEntry::PASSWORD_RESET, $target, 'Temporary password issued; it must be changed at the next sign-in.');

                return $temporary;
        }

        return null;
    }
}
