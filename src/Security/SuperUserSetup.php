<?php

namespace App\Security;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;

/**
 * Defines the Coreware super user (see SuperUser) the first time a console command needs it and
 * none is configured: asks for a name and a password and saves the name and the password's hash
 * to .env.local (APP_SUPERUSER_USERNAME, APP_SUPERUSER_PASSWORD_HASH).
 */
final class SuperUserSetup
{
    private const USERNAME_PATTERN = '/^[A-Za-z0-9._@-]{3,180}$/';
    private const ATTEMPTS = 3;

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /** Returns the new super user's name, or null when no valid password was confirmed. */
    public function run(SymfonyStyle $io): ?string
    {
        $io->warning('No Coreware super user is defined on this server. Define it now: it is needed for every console command, and to lock and unlock the app from the sign-in page.');

        $username = $io->ask('Super user name', null, static function (?string $value): string {
            $value = trim((string) $value);
            if (!preg_match(self::USERNAME_PATTERN, $value)) {
                throw new \RuntimeException('Use 3 to 180 letters, digits, dots, dashes, underscores or @.');
            }

            return $value;
        });

        for ($attempt = 1; $attempt <= self::ATTEMPTS; ++$attempt) {
            $password = (string) $io->askHidden('Password (12 to 128 characters with upper case, lower case, a digit and a symbol)');
            if (null !== $problem = self::passwordProblem($password, $username)) {
                $io->error($problem);
                continue;
            }
            if ($password !== (string) $io->askHidden('Repeat the password')) {
                $io->error('The two passwords do not match.');
                continue;
            }

            $this->save($username, (new NativePasswordHasher())->hash($password));
            $io->success(\sprintf('Super user "%s" saved to .env.local.', $username));
            if (is_file($this->projectDir.'/.env.local.php')) {
                $io->warning('This server uses .env.local.php (composer dump-env). Run "composer dump-env prod" so the web pages use the new super user too.');
            }

            return $username;
        }

        return null;
    }

    public static function passwordProblem(string $password, string $username): ?string
    {
        return match (true) {
            mb_strlen($password) < 12 || mb_strlen($password) > 128 => 'Use 12 to 128 characters.',
            !preg_match('/\p{Lu}/u', $password) || !preg_match('/\p{Ll}/u', $password) || !preg_match('/\d/', $password) || !preg_match('/[^\p{L}\d]/u', $password) => 'Include an upper-case letter, a lower-case letter, a digit and a symbol.',
            '' !== $username && str_contains(mb_strtolower($password), mb_strtolower($username)) => 'The password cannot contain the super user name.',
            default => null,
        };
    }

    /** Replaces any APP_SUPERUSER_* lines in .env.local, keeping the rest of the file. */
    private function save(string $username, string $hash): void
    {
        $file = $this->projectDir.'/.env.local';
        $content = is_file($file) ? (string) file_get_contents($file) : '';
        $content = preg_replace('/^\s*APP_SUPERUSER_(USERNAME|PASSWORD_HASH)=.*(\R|$)/m', '', $content);
        $content = rtrim($content)."\n\n"
            ."# Coreware super user, defined on first console use (see App\\Security\\SuperUserSetup).\n"
            .'APP_SUPERUSER_USERNAME='.$username."\n"
            // Single quotes: the hash contains "$", which would otherwise be read as a variable.
            ."APP_SUPERUSER_PASSWORD_HASH='".$hash."'\n";

        $this->filesystem->dumpFile($file, ltrim($content));
        try {
            $this->filesystem->chmod($file, 0640);
        } catch (\Throwable) {
            // Not supported on every filesystem (e.g. Windows); the file keeps its permissions.
        }
    }
}
