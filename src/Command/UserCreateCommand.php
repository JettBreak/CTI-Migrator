<?php

namespace App\Command;

use App\Enum\UserRole;
use App\Security\UserAdministration;
use App\Security\UserAdministrationException;
use App\Security\UserAudit;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates an account at once, without the second administrator's approval: for the first user
 * administrators, and for recovery. Everything else goes through the User administration pages.
 * Recorded in the account audit trail as done by "console:<os user>".
 */
#[AsCommand(
    name: 'app:user:create',
    description: 'Create an account (officer, approver or admin) and print its temporary password',
)]
final class UserCreateCommand
{
    private const ROLES = ['officer' => UserRole::Officer, 'approver' => UserRole::Approver, 'admin' => UserRole::Admin];

    public function __construct(private readonly UserAdministration $admin)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Username: lower-case letters, digits, dots, dashes and underscores')] string $username,
        #[Option('The person\'s full name')] string $name = '',
        #[Option('officer (maker), approver (checker) or admin (user administrator)')] string $role = '',
    ): int {
        $role = self::ROLES[$role] ?? null;
        if ('' === trim($name) || null === $role) {
            $io->error('Give --name="Full Name" and --role=officer|approver|admin.');

            return Command::INVALID;
        }

        try {
            $password = $this->admin->createDirectly($username, $name, $role, UserAudit::consoleActor());
        } catch (UserAdministrationException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Account "%s" created as %s.', mb_strtolower(trim($username)), $role->label()));
        $io->writeln(sprintf('Temporary password (shown once; must be changed at the first sign-in): <info>%s</info>', $password));

        return Command::SUCCESS;
    }
}
