# Installation guide

Coreware Data Migration is a Symfony 8.1 web application for replacing linked account numbers
in core banking, with maker-checker approval. It consists of:

- **The web application** (`public/index.php`), used by migration officers (makers), migration
  approvers (checkers) and user administrators.
- **Two background workers** (`messenger:consume`), switched on and off together from the
  *Background worker* page: one prepares large exports, the other validates and applies large
  batches and runs hourly maintenance. Running side by side, an export never waits for a batch.
- **Three MySQL databases:**

| Connection | Env variable | Default name | Access |
|---|---|---|---|
| App database | `APP_DATABASE_URL` | `data_migration` | Owned by this app: accounts, batches, audit trails, queue. Created and migrated by the app. |
| Core banking | `DATABASE_URL` | `coreapp_fusion` | Read for the directories; `prmaster` and `prlinkxx` updated when a batch is approved. |
| Core security | `CORE_SECURITY_DATABASE_URL` | `CoreSecurity` | Its `GetDataFromToken` function is called to show card numbers (masked). |

> Never point a development or test installation at the live core databases. Use a copy.

---

## 1. Requirements

| | |
|---|---|
| PHP | 8.4 or later (CLI and web), with the PDO MySQL driver (`pdo` and `pdo_mysql`), `ctype` and `iconv`. Verify the CLI driver with `php -r "print_r(PDO::getAvailableDrivers());"`. `opcache`, `intl` and `mbstring` are recommended. |
| Composer | 2.x |
| MySQL | The same major version as the core server (8.4). The app database may live on the core server or on its own server. |
| Web server | Any server that can run PHP through a front controller (nginx or Apache with PHP-FPM, IIS with FastCGI). **HTTPS is required** in production: the session cookie is marked secure and HSTS is sent. |
| OS | Windows or Linux. The worker is started as a detached process: through PowerShell/WMI on Windows, and through `setsid` on Linux. |
| Development only | [Symfony CLI](https://symfony.com/download) (`symfony serve`), and Docker if you want the bundled MySQL (`compose.yaml`). |

### PHP settings

Mapping files can be up to 200 MB, and importing a few hundred thousand rows takes longer than
PHP's default 30 seconds. Set these values in the web server's PHP configuration. The `php.ini` in
the project root does this for `symfony serve` only.

```ini
upload_max_filesize = 200M
post_max_size = 210M
max_input_time = 300
max_execution_time = 300
```

To accept larger files, raise both these values and `app.batch.upload_max_size` in
`config/services.yaml`.

---

## 2. Database accounts

Ask the DBA for one MySQL user (or one per connection) with at least:

- **`data_migration`:** all privileges, because the app creates and migrates its own tables. For a
  pre-created database, use `CREATE, ALTER, INDEX, DROP, SELECT, INSERT, UPDATE, DELETE, REFERENCES`.
- **`coreapp_fusion`:**
  - `SELECT` on `prmaster`, `prlinkxx`, `prstatus` and `customer`
  - `UPDATE` on `prmaster` and `prlinkxx`
  - `EXECUTE` on `sp_insertlogclixx`
  - The core audit trigger on `prlinkxx` (`tr_prlinkxx_upd` → `sp_auditlog`) must be able to run
    as that user.
- **`CoreSecurity`:** `EXECUTE` on the function `GetDataFromToken`.

The app refuses to create its tables in a core database (`app:database:init` and every migration
check for this).

---

## 3. Get the code and install dependencies

```bash
git clone <repository-url> cti-migrator
cd cti-migrator
```

Development:

```bash
composer install
```

Production:

```bash
composer install --no-dev --optimize-autoloader
```

The directory `var/` (cache, logs, sessions, uploads and exports) must be writable by both the web
server user and the user the worker runs as (the same user: the web app starts the worker).

---

## 4. Define the Coreware super user

The super user is the installation's break-glass account. Its credentials are asked for before
every `bin/console` command (see *Console authentication*), and it is the only one who can lock and
unlock the whole app from the sign-in page (see *System lockout*).

It is **not** an app account: it is not in the app database, it cannot sign in to the web pages,
and the app's user administrators can't see, change or remove it. Its name and password hash live
in the server configuration (`APP_SUPERUSER_USERNAME`, `APP_SUPERUSER_PASSWORD_HASH`). Decide
beforehand who holds its password (e.g. the system owner, kept in the bank's password vault or a
sealed envelope), because anyone who runs a console command on the server needs it.

Define it before any other console command, because the commands in the next steps ask for it.
From the project directory, in an interactive terminal (not a script, a pipe or a scheduled task),
as the user that owns the project files:

```bash
php bin/console about
```

`composer install` doesn't ask for it (the commands it runs are exempt), so this is the first
command that does. Because no super user is configured yet, it asks you to define one:

1. **Super user name:** 3 to 180 letters, digits, dots, dashes, underscores or `@` (e.g.
   `coreware.super`).
2. **Password:** 12 to 128 characters, with upper case, lower case, a digit and a symbol, and not
   containing the name. It isn't shown as you type.
3. **Repeat the password.** You get three tries at steps 2 and 3; after that the command stops and
   nothing is saved. Run it again.

The name and a bcrypt/Argon2 hash of the password (never the password itself) are then added to
`.env.local`, which is created if missing. Any older `APP_SUPERUSER_*` lines are replaced, and the
rest of the file is kept:

```bash
# Coreware super user, defined on first console use (see App\Security\SuperUserSetup).
APP_SUPERUSER_USERNAME=coreware.super
APP_SUPERUSER_PASSWORD_HASH='$2y$13$…'
```

Keep the single quotes around the hash: it contains `$`. On Linux the file is made readable by its
owner and group only (`0640`); on Windows, restrict it with the folder's permissions.

Then check it: run `php bin/console about` again. It must now ask for **Super user** and
**Password**, and run after you give them. Five wrong attempts in 15 minutes pause the console for
that operating-system user.

> The definition is only written to *Account audit trail* when the app database already exists. On a
> new server it is logged in `var/log/` instead, because `app:database:init` hasn't run yet. Every
> later console run is recorded under *Account audit trail* (target `system`).

### Generating a password hash with the console

To set or change the password hash by hand (for example to put it straight into the secrets vault
or a real environment variable), use Symfony's password hashing command. It uses the same hasher
as the app. From the project directory, in an interactive terminal:

```bash
php bin/console security:hash-password --env=prod
```

1. Like every console command, it first asks for the **current** super user. On a new server with
   no super user yet, it asks you to define one instead (see above), which already writes the hash
   for you.
2. If it asks which user class to hash for, pick the first one offered
   (`PasswordAuthenticatedUserInterface`).
3. Type the new password at the **Type in your password to be hashed** prompt. It isn't shown as
   you type. Don't pass the password as an argument on the command line, because it would be saved
   in the shell history.
4. Copy the **Password hash** value it prints (starting with `$2y$13$`).

This command does **not** check the password rules. Follow them yourself: 12 to 128 characters,
with upper case, lower case, a digit and a symbol, and not containing the super user name.

Put the hash in `.env.local`, replacing the old value and keeping the single quotes:

```bash
APP_SUPERUSER_USERNAME=coreware.super
APP_SUPERUSER_PASSWORD_HASH='$2y$13$…'
```

Or put it in the vault with `php bin/console secrets:set APP_SUPERUSER_PASSWORD_HASH --env=prod`
(without quotes), or in a real environment variable. Check it by running `php bin/console about`,
which must accept the new password.

**Servers using `composer dump-env prod`:** the web pages read `.env.local.php`, not `.env.local`.
The command warns about this; run `composer dump-env prod` again after defining or changing the
super user, or the *Lockout* button won't accept it.

**Keeping the hash in the secrets vault instead:** once the vault keys exist (section 5), run
`php bin/console secrets:set APP_SUPERUSER_PASSWORD_HASH --env=prod`, paste the hash from
`.env.local` (without the quotes), then delete the `APP_SUPERUSER_PASSWORD_HASH` line from
`.env.local`. A value in `.env.local` or in a real environment variable takes precedence over the
vault. The vault is per environment: a hash stored with `--env=prod` is only read when
`APP_ENV=prod`. A command run in `dev` then finds a name but no hash, and asks you to define the
super user again (press Ctrl+C there).

**Changing the password:** remove both `APP_SUPERUSER_*` lines from `.env.local` (and the secret, if
you used the vault), then run any console command: it defines the super user again as above. The same
applies when the password is lost. While you still know the current password, you can instead
generate a new hash with the console (see *Generating a password hash with the console*) and
replace the `APP_SUPERUSER_PASSWORD_HASH` value. Access to that file is the real control over the
super user, so restrict who can edit it.

---

## 5. Configure the environment

`.env` is committed and holds defaults only. Put real values in `.env.local` (not committed), in
real environment variables, or in the Symfony secrets vault for passwords.

| Variable | Example | Notes |
|---|---|---|
| `APP_ENV` | `prod` | `dev` on developer machines only. |
| `APP_TIMEZONE` | `Asia/Manila` | Timezone dates are stored and shown in. Each table records its offset (e.g. `UTC+08:00`) in a `timezone` column. Don't change it on an installation that already has data. |
| `APP_SECRET` | 32+ random characters | Signs CSRF tokens and more. Generate with `php -r "echo bin2hex(random_bytes(32));"`. |
| `APP_DATABASE_URL` | `mysql://migr:***@db-host:3306/data_migration?serverVersion=8.4.6&charset=utf8mb4` | The app's own database. |
| `DATABASE_URL` | `mysql://migr:***@core-host:3306/coreapp_fusion?serverVersion=8.4.6&charset=utf8mb4` | Core banking. |
| `CORE_SECURITY_DATABASE_URL` | `mysql://migr:***@core-host:3306/CoreSecurity?serverVersion=8.4.6&charset=utf8mb4` | Core security. |
| `DEFAULT_URI` | `https://migration.bank.local` | Used to build links outside web requests. |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://app?auto_setup=0` | Keep the default: the queue lives in the app database. |
| `LOCK_DSN` | `flock` | Keep the default for a single server. For several web servers, use a shared store (e.g. a `mysql://` DSN). |
| `APP_SUPERUSER_USERNAME` | `coreware.super` | The Coreware super user: signs in to console commands and locks and unlocks the whole app (see *Console authentication* and *System lockout*). Not an app account. Leave both empty on a new server: section 4 defines them and saves them to `.env.local`. |
| `APP_SUPERUSER_PASSWORD_HASH` | `$2y$13$…` | Its password hash, written by section 4 (or by `php bin/console security:hash-password`, see *Generating a password hash with the console*). |

Set `serverVersion` in every URL to the exact MySQL version of that server. `app:database:init`
warns when the core server's version doesn't match.

### Production secrets (recommended over `.env.local`)

```bash
php bin/console secrets:generate-keys --env=prod
php bin/console secrets:set APP_SECRET --env=prod
php bin/console secrets:set DATABASE_URL --env=prod
php bin/console secrets:set CORE_SECURITY_DATABASE_URL --env=prod
php bin/console secrets:set APP_DATABASE_URL --env=prod
php bin/console secrets:set APP_SUPERUSER_PASSWORD_HASH --env=prod
```

Each of these asks for the super user defined in section 4. For `APP_SUPERUSER_PASSWORD_HASH`,
paste the hash from `.env.local` and then delete that line there (see section 4).

Commit `config/secrets/prod/*` **except** `prod.decrypt.private.php`. Copy that decryption key to
the server separately, or provide it as the `SYMFONY_DECRYPTION_SECRET` environment variable.

Then compile the environment for production:

```bash
composer dump-env prod
```

---

## 6. Create the app database

```bash
php bin/console app:database:init
```

It asks for the super user defined in section 4. If you skipped that section, it asks you to define
the super user first.

This creates `data_migration` if it is missing, runs every migration (accounts, batches, audit
trails, the message queue), and compares the core server's MySQL version with the configured one.
Run it again after every upgrade. It is safe to repeat.

---

## 7. Build assets and warm the cache (production)

```bash
php bin/console asset-map:compile
php bin/console cache:clear --env=prod
```

`asset-map:compile` writes the versioned JavaScript and CSS to `public/assets/`. Run both commands
after every upgrade.

---

## 8. Web server

Point the document root at **`public/`** and send every request that isn't a file to
`public/index.php`. Follow Symfony's guide for your server:
<https://symfony.com/doc/current/setup/web_server_configuration.html>.

- **HTTPS:** serve the site over HTTPS only, and redirect HTTP to HTTPS. When the site runs on its
  own HTTPS port (e.g. `https://10.22.70.55:7443`), a browser that sends plain `http://` to that
  port gets Apache's *"You're speaking plain HTTP to an SSL-enabled server port"* page. Browsers
  ignore the app's HSTS header for IP addresses, so redirect those requests in the port's
  `<VirtualHost>`, using the address people type:

  ```apache
  <VirtualHost *:7443>
      SSLEngine on
      # ...
      # Plain HTTP sent to this HTTPS port: send the browser to the site over HTTPS.
      ErrorDocument 400 https://10.22.70.55:7443/
  </VirtualHost>
  ```

  The browser lands on the start page, which leads to sign-in. Keep the address fixed: Apache
  refuses these requests before it has read their headers, so a redirect built from
  `%{HTTP_HOST}` comes out without a host and browsers reject it as `ERR_INVALID_REDIRECT`. This
  only replaces 400 errors produced by Apache itself; the app's own responses are unchanged.
  `Listen` lines stay outside `<VirtualHost>`.
- **Security headers:** the app sends CSP, `X-Frame-Options`, HSTS and no-store caching itself. Don't
  override them in the web server.
- **Behind a reverse proxy or load balancer:** tell Symfony to trust it, so client IPs in the audit
  trail and HTTPS detection are right. Set this in `.env.local`, adjusting the address:

  ```bash
  SYMFONY_TRUSTED_PROXIES=10.0.0.0/8
  ```

  Then add `trusted_proxies: '%env(SYMFONY_TRUSTED_PROXIES)%'` under `framework:` in
  `config/packages/framework.yaml`.
- **Outgoing access:** the pages load Tailwind from `cdn.tailwindcss.com` and fonts from Google Fonts,
  so browsers need access to those two hosts.

**Development:** run `symfony serve -d` instead. It serves `https://127.0.0.1:8000` and reads the
project's `php.ini`.

---

## 9. Create the first accounts

Accounts live in the app database. Every change on the *Users* page is requested by one user
administrator and approved by another. Create **two** administrators from the server console to
start:

```bash
php bin/console app:user:create admin.one --name="First Admin" --role=admin
php bin/console app:user:create admin.two --name="Second Admin" --role=admin
```

Each command prints a **temporary password once**. Give it to its owner through a secure channel.
They must choose their own password at their first sign-in.

The administrators then create the migration officers and approvers. One administrator uses
*Users → New account*, and the other approves the request. The approving administrator sees the
new user's temporary password once.

Roles:

| Role | Can |
|---|---|
| Migration Officer (maker) | Upload, validate and submit batches; request rollbacks; exports. |
| Migration Approver (checker) | Approve or reject batches and rollbacks submitted by someone else; everything officers can see. |
| User Administrator | Manage accounts only (no access to batches or core data). |

Recovery commands, run on the server and recorded in the account audit trail as
`console:<os user>`:

```bash
php bin/console app:user:account reset-password <username>
php bin/console app:user:account unlock <username>
php bin/console app:user:account disable <username>
php bin/console app:user:account enable <username>
```

---

## 10. Start the background worker

Sign in as a migration officer or approver, open **Background worker**, and switch it **on**. It
starts two detached processes:

| Worker | Command | Logs | Does |
|---|---|---|---|
| Exports | `bin/console messenger:consume exports` | `var/log/worker-exports.log`, `worker-exports-error.log` | generates exports larger than `app.export.sync_max_rows`, one at a time |
| Batches | `bin/console messenger:consume async scheduler_default` | `var/log/worker.log`, `worker-error.log` | validates and applies batches larger than `app.batch.sync_max_rows` (5,000 rows), and runs the hourly maintenance: deleting expired export files and disabling dormant accounts |

They run side by side so an export never waits for a long batch.

After a server restart the workers are off. Switch them on again from the page. To keep them running
under a process manager instead (systemd, NSSM or Supervisor), run both commands as the web server
user from the project directory, each as its own service:

```bash
php bin/console messenger:consume exports --sleep=1 -vv
```

```bash
php bin/console messenger:consume async scheduler_default --sleep=1 -vv
```

If you do this, don't also switch them on from the page. Run one of each, never two of the same.

---

## 11. Check the installation

- [ ] `php bin/console about` shows the right environment (`prod`) and debug `false`.
- [ ] `php bin/console lint:container` succeeds.
- [ ] The sign-in page loads over HTTPS, and the browser console shows no Content-Security-Policy errors.
- [ ] Both administrators can sign in, change their temporary password, and see *Users*.
- [ ] An officer created and approved on the *Users* page can sign in and see the *Overview* and
      the *Card directory* (this proves the core connections).
- [ ] The *Background worker* page reports **Running**, with both workers running, after switching it on.
- [ ] *Account audit trail* shows the sign-ins above.

---

## Account safeguards

These are set in `config/services.yaml` (`app.security.*`) and `config/packages/security.yaml`:

| Setting | Default |
|---|---|
| Failed sign-ins in a row before the account locks (an administrator unlocks it) | 5 |
| Sign-in throttling per username and IP | 5 attempts per 15 minutes |
| Password rules | 12–128 characters with upper case, lower case, a digit and a symbol; not containing the username |
| Previous passwords that can't be reused | 5 |
| Password expiry | 90 days |
| Session ends after inactivity | 15 minutes |
| Session ends after signing in, regardless of activity | 8 hours |
| Sessions per user | 1 (signing in elsewhere ends the other one) |
| Accounts disabled when unused for | 90 days |

Every sign-in (including failed ones), sign-out, expired session, password change and account change
is recorded with IP and browser under *Account audit trail*.

---

## System lockout

The super user (`APP_SUPERUSER_USERNAME` / `APP_SUPERUSER_PASSWORD_HASH`) can lock the whole app from
the **Lockout** button under the sign-in form, with a reason:

- **Lock now** – sign-in closes at once and everyone signed in is signed out on their next click.
- **Allow use for a number of days, then lock** – every page shows a countdown banner (red on the
  last three days); at the end the app locks by itself.

While locked, the sign-in form is replaced by the lock notice and an **Unlock** button, which asks
for the super user's credentials again. A batch already running in the background worker is allowed
to finish. Super user attempts are limited to 5 per 15 minutes per IP, and every lock, unlock and
failed attempt is recorded under *Account audit trail* (target `system`).

The lock is kept in `var/system-lock.prod.json`, not in the database, signed with a key derived from
`APP_SECRET`. **Keep `var/` across deployments.** A lock file that has been edited, or that was
signed with a previous `APP_SECRET`, keeps the app locked. To lift the lock on the server without
the super user (e.g. a lost password or a changed `APP_SECRET`):

```bash
php bin/console app:system:unlock "Reason for the audit trail"
```

This command asks for the super user like every other one. If its password is lost, remove the
`APP_SUPERUSER_USERNAME` and `APP_SUPERUSER_PASSWORD_HASH` lines from `.env.local`; the next command
then asks you to define a new super user.

---

## Console authentication

Every `bin/console` command asks for the Coreware super user's name and password before it runs,
each time. Wrong attempts are limited to 5 per 15 minutes per operating-system user, and each run
and failed attempt is recorded under *Account audit trail* (target `system`, with the OS user) once
the app database exists.

- **First use:** when no super user is configured, the first command asks you to define one (name,
  then a password of 12 to 128 characters with upper case, lower case, a digit and a symbol) and
  saves it to `.env.local`. On a server that uses `composer dump-env prod`, run that again
  afterwards so the web pages see the new super user too.
- **Not asked:** commands that run unattended: `cache:clear`, `assets:install` and
  `importmap:install` (run by `composer install`), `messenger:consume` (the background worker), and
  `list`, `help` and shell completion.
- **Scripts and scheduled tasks:** other commands need a terminal. Run from a script, a pipe or with
  `--no-interaction`, they stop with an error instead of waiting for a password.

---

## Data retention: batch rows are deleted after 90 days (fixed rule)

> **This is a fixed rule of the application, not a setting, and it cannot be turned off from the app.
> Deleted rows cannot be restored: the app keeps no copy of them, and only a database backup taken
> before the deletion still holds them.**

The rows of a mapping file (table `migration_row`) are **permanently deleted 90 days after a batch
ended** in one of these statuses:

| Status | Why it is safe to delete | 90 days count from |
|---|---|---|
| **Rejected** | An approver rejected it; it was never applied. | the rejection |
| **Invalid** | It had rows needing correction and was not submitted as it stood. | the upload (validation finishes right after it) |
| **Failed** | It stopped before renaming anything (a batch that renamed some accounts is *Halted* instead). | the failure |

- **Never deleted:** rows of **Completed**, **Halted**, **Rolled back** and in-progress batches. Rollback,
  the migrated-accounts report and the record of what changed in core depend on them.
- **Kept:** the batch itself (file name, status, row and account counts, dates) and its audit trail,
  which records each deletion (*Rows purged*, with how many rows).
- **When it runs:** as part of the regular cleanup: hourly while the background worker is on, with
  **Run cleanup now** on the *Background worker* page, and with `php bin/console app:exports:cleanup`.
  With the worker off and no manual cleanup, nothing is deleted.
- **What users see:** the batch page shows the date its rows will be deleted, and afterwards that they
  were deleted. An *Invalid* batch whose rows are gone can no longer proceed with its valid rows; upload
  the file again.
- **Changing it:** the period and statuses are constants in `src/Service/BatchRowPurger.php`. Changing
  them is a code change to be reviewed and released, not configuration.

If the rows of such batches must be kept longer (for an audit, for example), take and keep database
backups of the app database: once deleted, the rows are gone from the application for good.

---

## Upgrading

Check [UPGRADE.md](UPGRADE.md) first for steps specific to the release you are moving to. The
routine steps are:

```bash
git pull
composer install --no-dev --optimize-autoloader
php bin/console app:database:init
php bin/console asset-map:compile
php bin/console cache:clear --env=prod
```

Switch the background worker off before upgrading, and on again afterwards, so it picks up the new
code.

---

## Development setup

**Quick path:** `php bin/setup.php --check` (in a terminal) does the steps below in one go: packages, the app
database and its user on a local MySQL, `app:database:init` (with `--skip-core-check` while the core copies
are missing), two administrators on a new app database, and the tests. Options: `--root-password=…`,
`--no-admins`, `--no-console` (stop before the commands that need the super user). By hand:

```bash
composer install
docker compose up -d database
```

Load copies of the `coreapp_fusion` and `CoreSecurity` schemas and data into the local MySQL, then:

```bash
php bin/console app:database:init
php bin/console app:user:create admin.one --name="Dev Admin" --role=admin
symfony serve -d
```

Run the tests with `php bin/phpunit`. They use SQLite and an in-memory core, so they need no
database.

---

## Troubleshooting

| Symptom | Where to look |
|---|---|
| Any error page | `var/log/prod.log` (or `dev.log`, plus the web profiler at `/_profiler` in dev). |
| Worker won't start or stops | `var/log/worker-error.log`, `var/log/worker.log`, and the log tail on the *Background worker* page. |
| *Background worker* page says a worker process *does not report to this page*, or it won't start because *a worker is already running* | The worker cannot save its heartbeat to the app cache, usually because `var/share/<env>` was deleted or recreated by another user (for example a command run as root) while it ran; `worker-error.log` then repeats *Failed to save key "worker.heartbeat"* and the worker logs *cannot save its heartbeat* once. Make sure `var/share` and everything in it belong to the user the web server and the worker run as, **Force stop** on the page (it stops every worker of the app), then switch the worker on. Only one worker should run at a time: two can take up the same export or batch. |
| "Invalid username or password" for a known user | The reason (wrong password, locked, disabled, dormant) is recorded under *Account audit trail*. Unlock with an administrator or `app:user:account unlock`. |
| All administrators locked out | `php bin/console app:user:account unlock <admin>` or `reset-password <admin>` on the server. |
| A batch page says *Rows permanently deleted* | Its rows were removed under the fixed 90-day data-retention rule (see *Data retention*). They cannot be restored from the app; upload the file again. |
| A command stops with *needs Coreware authentication* | It was run without a terminal (script, pipe, `--no-interaction`). Run it in an interactive shell. |
| *No Coreware super user is defined* although it is in the secrets vault | The hash is in another environment's vault (e.g. `prod`) than the one the command runs in (`APP_ENV`, often `dev`). Press Ctrl+C, then run with `--env=prod`, set `APP_ENV=prod`, or add the hash (`secrets:reveal APP_SUPERUSER_PASSWORD_HASH --env=prod`) to `.env.local`. |
| Super user password lost | Remove the `APP_SUPERUSER_*` lines from `.env.local`; the next console command defines a new super user. |
| Sign-in page says *Data migration is locked* | The super user locked the app (reason under *Account audit trail*). Unlock with the super user, or `app:system:unlock` on the server. *Failed its integrity check* means the lock file was edited or `APP_SECRET` changed. |
| Uploads rejected as too large | PHP's `upload_max_filesize` / `post_max_size` (section 1) and `app.batch.upload_max_size`. |
| Page shows the loading animation forever or looks unstyled | Browser console. The pages need `cdn.tailwindcss.com` and Google Fonts to be reachable. |
