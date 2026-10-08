<?php

declare(strict_types=1);

/*
 * One-command development setup after `git clone` (INSTALL.md "Development setup"):
 *
 *   php bin/setup.php [--root-user=root] [--root-password=...] [--no-admins] [--no-console] [--check]
 *
 * 1. checks PHP, its extensions, Composer and that it runs in a terminal;
 * 2. composer install (also runs cache:clear, assets:install and importmap:install);
 * 3. local MySQL: creates the app database (APP_DATABASE_URL, e.g. data_migration) and its user
 *    when needed (MySQL root only on a local server), refuses an app database that is a core
 *    database, and checks that copies of the core databases (DATABASE_URL, CORE_SECURITY_DATABASE_URL)
 *    are loaded; it never creates or changes core data;
 * 4. the console steps, which ask for the Coreware super user like every console command (the first
 *    one asks you to define it and saves it to .env.local): app:database:init, then on a new app
 *    database two administrators, admin.one and admin.two (each prints a temporary password once);
 * 5. with --check, runs the tests (SQLite and an in-memory core: no database needed).
 *
 * --no-console stops before step 4 (for a script without a terminal); --no-admins skips the accounts.
 * Safe to run again: app:database:init only applies new migrations; accounts are only created
 * while the app database has none. Never point a development installation at the live core.
 */

const EXTENSIONS = ['pdo', 'pdo_mysql', 'ctype', 'iconv'];
/** Recommended, not required (INSTALL.md "Requirements"). */
const RECOMMENDED = ['intl', 'mbstring'];
const MYSQL_DOWN = 2002;
const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1'];

$root = dirname(__DIR__);
chdir($root);

$options = getopt('', ['root-user:', 'root-password:', 'no-admins', 'no-console', 'check', 'help']);
if (isset($options['help'])) {
    echo "Usage: php bin/setup.php [--root-user=root] [--root-password=...] [--no-admins] [--no-console] [--check]\n";
    exit(0);
}
$rootUser = (string) ($options['root-user'] ?? getenv('MYSQL_ROOT_USER') ?: 'root');
$rootPassword = (string) ($options['root-password'] ?? getenv('MYSQL_ROOT_PASSWORD') ?: '');
$console = !isset($options['no-console']);

step('Checking PHP and Composer');
if (\PHP_VERSION_ID < 80400) {
    fail('PHP 8.4 or newer is required (this is '.\PHP_VERSION.').');
}
$missing = array_filter(EXTENSIONS, static fn (string $extension): bool => !extension_loaded($extension));
if ([] !== $missing) {
    fail('Enable these PHP extensions in php.ini: '.implode(', ', $missing).' ('.(php_ini_loaded_file() ?: 'no php.ini loaded').').');
}
foreach (RECOMMENDED as $extension) {
    if (!extension_loaded($extension)) {
        echo "Note: PHP extension $extension is recommended but not enabled.\n";
    }
}
if (!in_array('mysql', \PDO::getAvailableDrivers(), true)) {
    fail('The PDO MySQL driver is unavailable to '.\PHP_BINARY.'. Enable pdo_mysql for the CLI PHP binary (not only PHP-FPM/Apache), then verify with: '.\PHP_BINARY." -r \"print_r(PDO::getAvailableDrivers());\"");
}
if (!quiet('composer --version')) {
    fail('Composer 2 is not on the PATH: https://getcomposer.org/download/');
}
if ($console && !(\defined('STDIN') && stream_isatty(\STDIN))) {
    fail('Run this in a terminal: the console steps ask for the Coreware super user. (Or pass --no-console to stop before them.)');
}
echo 'PHP '.\PHP_VERSION.", extensions and Composer OK\n";

step('Installing dependencies');
run('composer install --no-interaction');
require $root.'/vendor/autoload.php';

step('Databases');
$env = envValues($root, 'dev');
$app = database($env, 'APP_DATABASE_URL');
$cores = [database($env, 'DATABASE_URL'), database($env, 'CORE_SECURITY_DATABASE_URL')];
foreach ($cores as $core) {
    if (sameDatabase($app, $core)) {
        fail("APP_DATABASE_URL points at the core database \"{$core['name']}\". Give the app its own database (e.g. data_migration) in .env.local.");
    }
}
prepareAppDatabase($app, $cores, $rootUser, $rootPassword);
$missingCores = missingCoreDatabases($app, $cores);
if ([] !== $missingCores) {
    echo "\nNote: no copy of ".implode(' / ', $missingCores)." on this server yet. Load copies of the core schemas and data\n"
        ."(never the live databases) before using the directories or batches. Until then the core check is skipped.\n";
}

if ($console) {
    step('Console steps (Coreware super user)');
    echo "Each of the next commands asks for the Coreware super user. The first time, it asks you to define one\n"
        ."(saved to .env.local); keep its password safe: every console command needs it.\n\n";
    run(console('app:database:init'.([] !== $missingCores ? ' --skip-core-check' : '')));

    if (!isset($options['no-admins'])) {
        $pdo = connect($app['host'], $app['port'], $app['user'], $app['password']);
        $accounts = null === $pdo ? -1 : (int) $pdo->query('SELECT COUNT(*) FROM '.identifier($app['name']).'.app_user')->fetchColumn();
        if (0 === $accounts) {
            echo "\nCreating two administrators (every account change needs a second one to approve it).\n"
                ."Each prints a temporary password once: note it; it must be changed at the first sign-in.\n";
            run(console('app:user:create admin.one --name="First Admin" --role=admin'));
            run(console('app:user:create admin.two --name="Second Admin" --role=admin'));
        } else {
            echo "\nAccounts already exist; none created.\n";
        }
    }
} else {
    echo "\nSkipped the console steps (--no-console). In a terminal, run: php bin/console app:database:init\n";
}

if (isset($options['check'])) {
    step('Tests');
    run(escapeshellarg(\PHP_BINARY).' bin/phpunit');
}

step('Done');
echo <<<TEXT
    Start the app:   symfony serve -d   (https://127.0.0.1:8000; reads the project php.ini for large uploads;
                     add --port=8010 if another app already uses 8000)
    Sign in:         admin.one / admin.two with the temporary passwords printed above
    Workers:         switch them on from the Background worker page

    TEXT;

// ---------------------------------------------------------------------------------------

/**
 * The env values Symfony would load for $env, in its order.
 *
 * @return array<string, string>
 */
function envValues(string $root, string $env): array
{
    $files = ['.env', '.env.local', ".env.$env", ".env.$env.local"];
    $values = [];
    foreach ($files as $file) {
        if (is_file("$root/$file")) {
            $values = array_merge($values, (new Symfony\Component\Dotenv\Dotenv())->parse((string) file_get_contents("$root/$file"), $file));
        }
    }

    return $values;
}

/**
 * @param array<string, string> $env
 *
 * @return array{host: string, port: int, user: string, password: string, name: string}
 */
function database(array $env, string $variable): array
{
    $url = parse_url($env[$variable] ?? '');
    if (!is_array($url) || 'mysql' !== ($url['scheme'] ?? null) || !isset($url['host'], $url['user'], $url['path'])) {
        fail("$variable must be a mysql:// URL with user, host and database name.");
    }

    return [
        'host' => $url['host'],
        'port' => $url['port'] ?? 3306,
        'user' => rawurldecode($url['user']),
        'password' => rawurldecode($url['pass'] ?? ''),
        'name' => ltrim(rawurldecode($url['path']), '/'),
    ];
}

/**
 * @param array{host: string, port: int, user: string, password: string, name: string} $a
 * @param array{host: string, port: int, user: string, password: string, name: string} $b
 */
function sameDatabase(array $a, array $b): bool
{
    $host = static fn (string $h): string => in_array(strtolower($h), LOCAL_HOSTS, true) ? 'local' : strtolower($h);

    return $host($a['host']) === $host($b['host']) && $a['port'] === $b['port'] && strtolower($a['name']) === strtolower($b['name']);
}

/**
 * Creates the app database as its user when it can. Otherwise, on a local server only, signs in
 * as MySQL root to create it and the user, and grants that user the app database plus the local
 * core copies (development). A remote server is the DBA's (INSTALL.md "Database accounts").
 *
 * @param array{host: string, port: int, user: string, password: string, name: string}       $app
 * @param list<array{host: string, port: int, user: string, password: string, name: string}> $cores
 */
function prepareAppDatabase(array $app, array $cores, string $rootUser, string $rootPassword): void
{
    $pdo = connect($app['host'], $app['port'], $app['user'], $app['password'], $error);
    if (MYSQL_DOWN === $error) {
        fail(sprintf('MySQL is not running on %s:%d. Start it (or: docker compose up -d database) and run this again.', $app['host'], $app['port']));
    }
    if (null !== $pdo && createDatabase($pdo, $app['name'])) {
        checkVersion($pdo);
        echo "App database ready ({$app['name']}) as user {$app['user']}.\n";

        return;
    }
    if (!in_array(strtolower($app['host']), LOCAL_HOSTS, true)) {
        fail("The user {$app['user']} cannot create {$app['name']} on {$app['host']}. Ask the DBA for the accounts in INSTALL.md section 2.");
    }

    echo "Creating {$app['name']} and the {$app['user']} user as MySQL {$rootUser}...\n";
    $admin = connect($app['host'], $app['port'], $rootUser, $rootPassword);
    if (null === $admin) {
        fail("Could not sign in to MySQL as {$rootUser}. Pass --root-user / --root-password (or MYSQL_ROOT_PASSWORD).");
    }
    checkVersion($admin);
    createDatabase($admin, $app['name']);
    $names = array_unique(array_merge([$app['name']], array_column(array_filter($cores, static fn (array $c): bool => $c['user'] === $app['user']), 'name')));
    foreach (['localhost', '127.0.0.1'] as $host) {
        $account = $admin->quote($app['user']).'@'.$admin->quote($host);
        $admin->exec("CREATE USER IF NOT EXISTS $account IDENTIFIED BY ".$admin->quote($app['password']));
        foreach ($names as $name) {
            $admin->exec('GRANT ALL PRIVILEGES ON '.identifier($name).".* TO $account");
        }
    }
    $admin->exec('FLUSH PRIVILEGES');

    if (null === connect($app['host'], $app['port'], $app['user'], $app['password'])) {
        fail("The MySQL user {$app['user']} already exists with another password. Put that password in the database URLs in .env.local.");
    }
    echo "App database ready ({$app['name']}).\n";
}

/**
 * The core databases (by name) that the app user cannot see on their server.
 *
 * @param array{host: string, port: int, user: string, password: string, name: string}       $app
 * @param list<array{host: string, port: int, user: string, password: string, name: string}> $cores
 *
 * @return list<string>
 */
function missingCoreDatabases(array $app, array $cores): array
{
    $missing = [];
    foreach ($cores as $core) {
        $pdo = connect($core['host'], $core['port'], $core['user'], $core['password']);
        $found = null !== $pdo && false !== $pdo->query('SHOW DATABASES LIKE '.$pdo->quote(addcslashes($core['name'], '_%')))->fetchColumn();
        if (!$found) {
            $missing[] = $core['name'];
        }
    }

    return $missing;
}

function createDatabase(PDO $pdo, string $name): bool
{
    try {
        $pdo->exec('CREATE DATABASE IF NOT EXISTS '.identifier($name).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    } catch (PDOException) {
        return false;
    }

    return true;
}

function checkVersion(PDO $pdo): void
{
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    if (str_contains(strtolower($version), 'mariadb')) {
        fail("This is MariaDB ($version); the app needs MySQL 8.4 like the core server.");
    }
    if (!str_starts_with($version, '8.4.')) {
        echo "Warning: MySQL $version; use the same major version as the core server (8.4).\n";
    }
}

function connect(string $host, int $port, string $user, string $password, ?int &$error = null): ?PDO
{
    try {
        $error = null;

        return new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } catch (PDOException $e) {
        $error = (int) ($e->errorInfo[1] ?? $e->getCode());

        return null;
    }
}

function identifier(string $name): string
{
    return '`'.str_replace('`', '``', $name).'`';
}

function console(string $arguments): string
{
    return escapeshellarg(\PHP_BINARY).' bin/console '.$arguments;
}

function run(string $command): void
{
    echo "> $command\n";
    passthru($command, $code);
    if (0 !== $code) {
        fail("Failed (exit code $code): $command");
    }
}

function quiet(string $command): bool
{
    exec($command.(\PHP_OS_FAMILY === 'Windows' ? ' >NUL 2>&1' : ' >/dev/null 2>&1'), $output, $code);

    return 0 === $code;
}

function step(string $title): void
{
    echo "\n== $title\n";
}

function fail(string $message): never
{
    fwrite(\STDERR, "\nSetup stopped: $message\n");
    exit(1);
}
