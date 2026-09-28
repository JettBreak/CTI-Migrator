<?php

namespace App\Command;

use App\Repository\UserRepository;
use App\Security\UserAdministration;
use App\Security\UserAdministrationException;
use App\Security\UserAudit;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recovery on the server, without a second administrator: reset a password, unlock, disable or
 * enable an account (e.g. when no other user administrator can sign in). Recorded in the account
 * audit trail as done by "console:<os user>".
 */
#[AsCommand(
    name: 'app:user:account',
    description: 'Reset the password of, unlock, disable or enable an account (recovery)',
)]
final class UserAccountCommand
{
    private const ACTIONS = ['reset-password', 'unlock', 'disable', 'enable'];

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserAdministration $admin,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('reset-password, unlock, disable or enable')] string $action,
        #[Argument('Username')] string $username,
    ): int {
        if (!\in_array($action, self::ACTIONS, true)) {
            $io->error('The action is one of: '.implode(', ', self::ACTIONS).'.');

            return Command::INVALID;
        }
        $user = $this->users->findOneByUsername(mb_strtolower(trim($username)));
        if (null === $user) {
            $io->error(sprintf('There is no account "%s".', $username));

            return Command::FAILURE;
        }

        $by = UserAudit::consoleActor();
        try {
            match ($action) {
                'reset-password' => $io->writeln(sprintf('Temporary password (shown once; must be changed at the next sign-in): <info>%s</info>', $this->admin->resetPasswordDirectly($user, $by))),
                'unlock' => $this->admin->unlockDirectly($user, $by),
                'disable' => $this->admin->setActiveDirectly($user, false, $by),
                'enable' => $this->admin->setActiveDirectly($user, true, $by),
            };
        } catch (UserAdministrationException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Done: %s "%s".', $action, $user->getUsername()));

        return Command::SUCCESS;
    }
}
