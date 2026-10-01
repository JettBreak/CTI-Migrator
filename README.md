# Coreware Migration Control

Web application for replacing (renumbering) card-linked accounts in the Coreware core database, with
validation against live core data, maker-checker approval, a full audit trail, exports, and rollback.

Symfony 8.1 on PHP 8.4+, MySQL 8.4. Two databases are used:

- **core** (`coreapp`, plus `CoreSecurity`): the core banking data that is read and changed;
- **app** (e.g. `data_migration`): this application's own tables (batches, rows, exports, audit, queue).

## Deploying to a new environment

The repository does not contain third-party code, compiled assets or secrets. These are rebuilt or
provided on every new server:

| Not in git | Recreated by |
|---|---|
| `vendor/` (PHP packages) | `composer install` |
| `assets/vendor/` (Stimulus, Turbo, d3-geo, topojson-client) | `importmap:install` (run automatically by `composer install`) |
| `public/assets/` (compiled CSS/JS) | `asset-map:compile` (production) |
| `.env.local` (real configuration and secrets) | you create it |
| `var/` (cache, logs, uploads, export files) | created automatically |

### Requirements

- PHP **8.4 or later** with `pdo_mysql`, `ctype` and `iconv` (plus the PHP CLI, used by the background worker).
- Composer.
- MySQL 8.4 access to the core database and to an app database.
- Internet access during installation (see [Servers without internet](#servers-without-internet)).

### 1. Configure the environment

Create `.env.local` in the project root (it is git-ignored), or set the same values as real environment
variables. `.env` only holds defaults; never put secrets in it.

```dotenv
APP_ENV=prod
APP_SECRET=<a long random string>

# Core data (read by the directories, changed by approved batches and rollbacks)
DATABASE_URL="mysql://user:password@host:3306/coreapp_fusion?serverVersion=8.4&charset=utf8mb4"
CORE_SECURITY_DATABASE_URL="mysql://user:password@host:3306/CoreSecurity?serverVersion=8.4&charset=utf8mb4"
# This application's own tables; must NOT point at the core database
APP_DATABASE_URL="mysql://user:password@host:3306/data_migration?serverVersion=8.4&charset=utf8mb4"

MESSENGER_TRANSPORT_DSN=doctrine://app?auto_setup=0
LOCK_DSN=flock

# Sign-in passwords, stored as hashes: php bin/console security:hash-password
MIGRATION_OFFICER_PASSWORD_HASH='<hash>'
MIGRATION_APPROVER_PASSWORD_HASH='<hash>'
```

### 2. Install PHP packages

```bash
composer install --no-dev --optimize-autoloader
```

This also runs `cache:clear`, `assets:install` and `importmap:install`.

### 3. Compile the front-end assets

```bash
php bin/console asset-map:compile
```

### 4. Create or update the app database tables

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

Run this on every deployment; it only applies migrations that have not run yet. Migrations refuse to
run if `APP_DATABASE_URL` points at the core database.

### 5. PHP settings on the web server

Mapping files can be up to 200 MB and large imports take a while, so the web server's PHP needs:

```ini
upload_max_filesize = 200M
post_max_size = 210M
max_execution_time = 300
max_input_time = 300
```

The `php.ini` in the project root only applies to the Symfony local server (`symfony serve`). On
Apache, IIS or PHP-FPM, set these in that server's own PHP configuration. Without them, uploads larger
than PHP's defaults (2 MB / 8 MB) are rejected before the application sees them.

### 6. Start the background worker

Large batches (validation, replacement, rollback) and exports run in the background worker. Turn it on
from the **Background worker** page after deploying.

**Restart the worker (off, then on) after every deployment.** It loads the code once when it starts,
so a worker left running keeps using the old version.

### Servers without internet

- `composer install` and `importmap:install` download packages. On a server without internet access,
  run them on a build machine with the same PHP version and copy `vendor/` and `assets/vendor/` across.
- The pages currently load **Tailwind CSS** (`cdn.tailwindcss.com`) and the **DM Sans / Manrope** fonts
  (Google Fonts) from the internet. Without access, text falls back to system fonts and most of the
  page layout breaks. For an offline environment, bundle both locally first
  (`symfonycasts/tailwind-bundle`, and the font files under `assets/fonts/`).

## Operating notes

- **Use production mode** (`APP_ENV=prod`, `APP_DEBUG=0`) for real migrations. Debug mode records
  every SQL statement and is much slower on large batches.
- **A batch stuck on "Validating", "Processing" or "Rolling back" while the worker is idle**: the
  worker handling it was stopped part-way. It is picked up again automatically about an hour later, or
  at once with:
  ```bash
  php bin/console app:batch:requeue <batch id>
  ```
- **Rollback** of a completed batch is maker-checker: a migration officer requests it with a reason,
  and a different approver approves it. Accounts that changed in core since the replacement are left
  alone and listed in a "Not rolled back" export.
- Export files are deleted after 7 days (`app.export.retention` in `config/services.yaml`).
- Logs: `var/log/<env>.log`, and the worker's `var/log/worker.log` / `worker-error.log`.

## Local development

```bash
composer install
php bin/console doctrine:migrations:migrate
symfony serve -d
```

The project `php.ini` raises the upload limits for `symfony serve`. Run the tests with
`php bin/phpunit`; they use SQLite and an in-memory stand-in for core, so they never touch a real database.
