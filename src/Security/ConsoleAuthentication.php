<?php

namespace App\Security;

use App\Entity\UserAuditEntry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Asks for the Coreware super user's credentials (see SuperUser) before every console command,
 * except those that run unattended. When no super user is configured yet, the first command
 * defines one (see SuperUserSetup). Each run and each failed attempt is written to the account
 * audit trail when the app database is reachable (it is not yet during a first installation).
 *
 * Commands run with --no-interaction (scripts, cron) are refused unless they are unattended ones.
 */
final class ConsoleAuthentication
{
    /** Run without anyone at the keyboard: by composer install, by the background worker, or to list and complete commands. */
    public const UNATTENDED = [
        'list', 'help', 'completion', '_complete',
        'cache:clear', 'assets:install', 'importmap:install',
        'messenger:consume',
    ];
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly SuperUser $superUser,
        private readonly SuperUserSetup $setup,
        private readonly RateLimiterFactoryInterface $superuserLimiter,
        private readonly UserAudit $audit,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        // Optional for one boot so a deployment with a stale compiled container can run
        // cache:clear and rebuild with the new authorization service.
        private readonly ?ConsoleCommandAuthorization $authorization = null,
    ) {
    }

    #[AsEventListener(event: ConsoleEvents::COMMAND, priority: 1024)]
    public function onCommand(ConsoleCommandEvent $event): void
    {
        $name = $event->getCommand()?->getName();
        if (null === $name || \in_array($name, self::UNATTENDED, true)) {
            return;
        }

        if ($this->authorization?->permitsInternalCommand()) {
            return;
        }

        // Prompts go to stderr, so a command's own output can still be piped or redirected.
        $output = $event->getOutput();
        $io = new SymfonyStyle($event->getInput(), $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);
        $osUser = UserAudit::consoleActor();

        if (!$event->getInput()->isInteractive() || !self::hasKeyboard($event->getInput())) {
            $io->error(\sprintf('"%s" needs Coreware authentication. Run it in a terminal (not from a script or with --no-interaction).', $name));
            $event->disableCommand();

            return;
        }

        if (!$this->superUser->isConfigured()) {
            $username = $this->setup->run($io);
            if (null === $username) {
                $io->error('No super user was defined; the command did not run.');
                $event->disableCommand();

                return;
            }
            $this->record($username, UserAuditEntry::SUPERUSER_DEFINED, \sprintf('Defined by %s before running %s.', $osUser, $name));

            return;
        }

        $io->text(\sprintf('<comment>Coreware authentication</comment> is required to run <info>%s</info>.', $name));
        for ($attempt = 1; $attempt <= self::ATTEMPTS; ++$attempt) {
            if (!$this->superuserLimiter->create($osUser)->consume()->isAccepted()) {
                $this->record($osUser, UserAuditEntry::SUPERUSER_THROTTLED, \sprintf('Too many super user attempts (console: %s); paused.', $name));
                $io->error('Too many attempts. Wait a few minutes before trying again.');
                $event->disableCommand();

                return;
            }

            $username = trim((string) $io->ask('Super user'));
            $password = (string) $io->askHidden('Password');
            if ($this->superUser->verify($username, $password)) {
                $this->record($username, UserAuditEntry::CONSOLE_AUTHENTICATED, \sprintf('Ran %s as %s.', $name, $osUser));

                return;
            }

            $this->record($username, UserAuditEntry::SUPERUSER_FAILED, \sprintf('Wrong super user credentials (console: %s, %s).', $name, $osUser));
            $io->error('Invalid super user name or password.');
        }

        $event->disableCommand();
    }

    /**
     * Whether someone can answer the prompts: an input stream given to the command (tests), or a
     * standard input that is a terminal. Symfony cannot tell on Windows that STDIN is a pipe and
     * would wait for an answer forever, so a script or scheduled task is refused instead.
     */
    private static function hasKeyboard(InputInterface $input): bool
    {
        if ($input instanceof StreamableInputInterface && null !== $input->getStream()) {
            return true;
        }

        return \defined('STDIN') && stream_isatty(\STDIN);
    }

    /**
     * Writes to the audit trail when its table exists. During a first installation the app database
     * is not created yet (app:database:init creates it); the entry then only goes to the log, and the
     * entity manager is left untouched for the command.
     */
    private function record(string $actor, string $action, string $details): void
    {
        try {
            $table = $this->em->getClassMetadata(UserAuditEntry::class)->getTableName();
            $this->em->getConnection()->executeQuery(\sprintf('SELECT 1 FROM %s WHERE 1 = 0', $table));
        } catch (\Throwable $e) {
            $this->logger->warning('Console authentication not written to the audit trail (app database unavailable): {actor} {action} {details}', ['actor' => $actor, 'action' => $action, 'details' => $details, 'exception' => $e]);

            return;
        }

        $this->audit->record($actor, $action, 'system', $details, flush: true);
    }
}
