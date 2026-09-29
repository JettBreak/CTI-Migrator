# Installation guide

Coreware Migration Control is a Symfony 8.1 web application for replacing linked account numbers
in core banking, with maker-checker approval. It consists of:

- **The web application** (`public/index.php`), used by migration officers (makers), migration
  approvers (checkers) and user administrators.
- **A background worker** (`messenger:consume`), switched on and off from the *Background worker*
  page. It validates and applies large batches, prepares large exports, and runs hourly
  maintenance.
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
| PHP | 8.4 or later (CLI and web), with `pdo_mysql`, `ctype` and `iconv`. `opcache`, `intl` and `mbstring` are recommended. |
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

## 4. Configure the environment

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

Set `serverVersion` in every URL to the exact MySQL version of that server. `app:database:init`
warns when the core server's version doesn't match.

### Production secrets (recommended over `.env.local`)

```bash
php bin/console secrets:generate-keys --env=prod
php bin/console secrets:set APP_SECRET --env=prod
php bin/console secrets:set DATABASE_URL --env=prod
php bin/console secrets:set CORE_SECURITY_DATABASE_URL --env=prod
php bin/console secrets:set APP_DATABASE_URL --env=prod
```

Commit `config/secrets/prod/*` **except** `prod.decrypt.private.php`. Copy that decryption key to
the server separately, or provide it as the `SYMFONY_DECRYPTION_SECRET` environment variable.

Then compile the environment for production:

```bash
composer dump-env prod
```

---

## 5. Create the app database

```bash
php bin/console app:database:init
```

This creates `data_migration` if it is missing, runs every migration (accounts, batches, audit
trails, the message queue), and compares the core server's MySQL version with the configured one.
Run it again after every upgrade. It is safe to repeat.

---

## 6. Build assets and warm the cache (production)

```bash
php bin/console asset-map:compile
php bin/console cache:clear --env=prod
```

`asset-map:compile` writes the versioned JavaScript and CSS to `public/assets/`. Run both commands
after every upgrade.

---

## 7. Web server

Point the document root at **`public/`** and send every request that isn't a file to
`public/index.php`. Follow Symfony's guide for your server:
<https://symfony.com/doc/current/setup/web_server_configuration.html>.

- **HTTPS:** serve the site over HTTPS only, and redirect HTTP to HTTPS.
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

## 8. Create the first accounts

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

## 9. Start the background worker

Sign in as a migration officer or approver, open **Background worker**, and switch it **on**. It
runs `bin/console messenger:consume async scheduler_default` as a detached process and writes to
`var/log/worker.log` and `var/log/worker-error.log`.

While it is on, it:

- validates and applies batches larger than `app.batch.sync_max_rows` (5,000 rows);
- generates exports larger than `app.export.sync_max_rows`;
- runs the hourly maintenance: deleting expired export files and disabling dormant accounts.

After a server restart the worker is off. Switch it on again from the page. To keep it running
under a process manager instead (systemd, NSSM or Supervisor), run the same command as the web
server user from the project directory:

```bash
php bin/console messenger:consume async scheduler_default --sleep=1 -vv
```

If you do this, don't also switch it on from the page.

---

## 10. Check the installation

- [ ] `php bin/console about` shows the right environment (`prod`) and debug `false`.
- [ ] `php bin/console lint:container` succeeds.
- [ ] The sign-in page loads over HTTPS, and the browser console shows no Content-Security-Policy errors.
- [ ] Both administrators can sign in, change their temporary password, and see *Users*.
- [ ] An officer created and approved on the *Users* page can sign in and see the *Overview* and
      the *Card directory* (this proves the core connections).
- [ ] The *Background worker* page reports **Running** after switching it on.
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
| "Invalid username or password" for a known user | The reason (wrong password, locked, disabled, dormant) is recorded under *Account audit trail*. Unlock with an administrator or `app:user:account unlock`. |
| All administrators locked out | `php bin/console app:user:account unlock <admin>` or `reset-password <admin>` on the server. |
| Uploads rejected as too large | PHP's `upload_max_filesize` / `post_max_size` (section 1) and `app.batch.upload_max_size`. |
| Page shows the loading dinosaur forever or looks unstyled | Browser console. The pages need `cdn.tailwindcss.com` and Google Fonts to be reachable. |
