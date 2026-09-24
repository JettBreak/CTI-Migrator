<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-step setup of the app's own database on whatever server APP_DATABASE_URL points at:
 * create it if missing, run migrations, and sanity-check the configuration against core.
 */
#[AsCommand(
    name: 'app:database:init',
    description: 'Create the app database (APP_DATABASE_URL) if missing, run migrations, and check the core connection settings',
)]
final class InitDatabaseCommand
{
    public function __construct(
        private readonly Connection $appConnection,
        private readonly Connection $coreappConnection,
        private readonly Connection $coreSecurityConnection,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        Command $command,
        #[Option('Skip connecting to core to compare its MySQL version with serverVersion')]
        bool $skipCoreCheck = false,
    ): int {
        $app = $this->appConnection->getParams();
        $io->title(sprintf('App database: %s on %s', $app['dbname'] ?? $app['path'] ?? '?', $app['host'] ?? 'local file'));

        try {
            self::assertSeparateFromCore($app, [$this->coreappConnection->getParams(), $this->coreSecurityConnection->getParams()]);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $application = $command->getApplication();
        $steps = [
            'Creating database if missing' => ['command' => 'doctrine:database:create', '--connection' => 'app', '--if-not-exists' => true],
            'Running migrations' => ['command' => 'doctrine:migrations:migrate', '--no-interaction' => true, '--allow-no-migration' => true],
        ];
        foreach ($steps as $label => $arguments) {
            $io->section($label);
            $input = new ArrayInput($arguments);
            $input->setInteractive(false);
            if (Command::SUCCESS !== $application->doRun($input, $io)) {
                $io->error($label.' failed.');

                return Command::FAILURE;
            }
        }

        if (!$skipCoreCheck) {
            $io->section('Checking core connection');
            $configured = $this->coreappConnection->getParams()['serverVersion'] ?? null;
            $actual = (string) $this->coreappConnection->fetchOne('SELECT VERSION()');
            $io->writeln(sprintf('Core server reports MySQL <info>%s</info>; configured serverVersion is <info>%s</info>.', $actual, $configured ?? 'not set'));
            if (null === $configured || !str_starts_with($actual, $configured)) {
                $io->warning(sprintf('Set serverVersion=%s in DATABASE_URL, CORE_SECURITY_DATABASE_URL and APP_DATABASE_URL so Doctrine targets the right MySQL version.', strtok($actual, '-')));
            }
        }

        $io->success('App database is ready.');

        return Command::SUCCESS;
    }

    /**
     * Refuses an app database that is one of the core databases: the app's tables and
     * migrations must never be created in core.
     *
     * @param array<string, mixed>       $app
     * @param list<array<string, mixed>> $cores
     */
    public static function assertSeparateFromCore(array $app, array $cores): void
    {
        $key = static fn (array $p) => strtolower(sprintf('%s:%s/%s', $p['host'] ?? '', $p['port'] ?? 3306, $p['dbname'] ?? $p['path'] ?? ''));
        foreach ($cores as $core) {
            if ($key($app) === $key($core)) {
                throw new \RuntimeException(sprintf('APP_DATABASE_URL points at the core database "%s". Give the app its own database (e.g. data_migration).', $core['dbname'] ?? '?'));
            }
        }
    }
}
