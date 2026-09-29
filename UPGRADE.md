# Upgrade guide

How to bring an existing installation up to date. Read the section for every version you skip,
oldest first. The routine steps for every upgrade are in [INSTALL.md → Upgrading](INSTALL.md#upgrading).

---

## Upgrading to database accounts and local-time dates

This release changes three things on an existing installation:

1. **Sign-in accounts move into the app database.** The two built-in accounts (`officer` and
   `approver`), whose password hashes were in `.env` / `.env.local` as `MIGRATION_OFFICER_PASSWORD_HASH`
   and `MIGRATION_APPROVER_PASSWORD_HASH`, no longer exist.
   - Accounts are now managed on the *Users* pages by user administrators, with maker-checker
     between two administrators.
   - Sign-in has lockout, password rules and expiry, session time limits, and an audit trail.
2. **Dates are stored and shown in local time** (`APP_TIMEZONE`, default `Asia/Manila`) instead of
   UTC.
   - Every app table with dates gets a `timezone` column (e.g. `UTC+08:00`), except `migration_row`,
     whose dates are in its batch's timezone.
   - Dates already stored are converted from UTC.
3. **The stylesheet is linked from the pages** instead of imported by `app.js`. The new
   Content-Security-Policy would block the old way. This needs a fresh asset build.

Plan for **downtime**:
- Everyone is signed out.
- Nobody can sign in until the first administrators have been created.
- The migration rewrites every row of `migration_row`. On a large database this takes minutes
  (about 5 minutes for a few hundred thousand rows on a developer machine).

### Before you start

- [ ] Make sure no batch is being processed or rolled back (*Account replacement*: nothing
      *Validating*, *Processing* or *Rolling back*), and no export is running.
- [ ] Switch the **background worker off** on the *Background worker* page and wait until it shows
      *Stopped*.
- [ ] **Back up the app database** (`data_migration`). The core databases are not changed by this
      upgrade.

  ```bash
  mysqldump --single-transaction --routines data_migration > data_migration-before-upgrade.sql
  ```

- [ ] Agree on the people who will be the **first two user administrators**, and on the officers
      and approvers to create after that.

### 1. Update the code

```bash
git pull
composer install --no-dev --optimize-autoloader
```

This release adds the `symfony/rate-limiter` package, which is used for sign-in throttling.

### 2. Update the configuration

In `.env.local` (or the server's environment variables):

- **Remove** `MIGRATION_OFFICER_PASSWORD_HASH` and `MIGRATION_APPROVER_PASSWORD_HASH`. Nothing reads
  them any more. If they are in the secrets vault, remove them there:

  ```bash
  php bin/console secrets:remove MIGRATION_OFFICER_PASSWORD_HASH --env=prod
  php bin/console secrets:remove MIGRATION_APPROVER_PASSWORD_HASH --env=prod
  ```

- **Set `APP_TIMEZONE`** if the server should not use the default `Asia/Manila`. Use a PHP timezone
  name, e.g. `APP_TIMEZONE=Asia/Manila`.

  > Decide this **before** running the migration. The migration converts the stored UTC dates into
  > this timezone and records it on every row. Don't change `APP_TIMEZONE` afterwards without a
  > data migration.

If you use `composer dump-env prod`, run it again after changing `.env.local`.

### 3. Migrate the app database

```bash
php bin/console app:database:init
```

This runs two new migrations:

| Migration | What it does |
|---|---|
| `Version20260928152550` | Creates `app_user`, `password_history`, `user_audit_entry` and `user_change_request`. |
| `Version20260928234346` | Adds `timezone` to `app_user`, `audit_entry`, `export_job`, `migration_batch`, `password_history`, `user_audit_entry` and `user_change_request`, and shifts all existing dates (including those in `migration_row`) from UTC to `APP_TIMEZONE`. |

Let it finish; don't interrupt the second migration while it updates `migration_row`.

### 4. Build assets and clear the cache

```bash
php bin/console asset-map:compile
php bin/console cache:clear --env=prod
```

Skipping `asset-map:compile` leaves the old `app.js`. The browser then blocks the stylesheet
import, so the page scripts stop working and pages stay on the loading screen.

### 5. Create the first administrators

```bash
php bin/console app:user:create admin.one --name="First Admin" --role=admin
php bin/console app:user:create admin.two --name="Second Admin" --role=admin
```

Each command prints a temporary password once. Hand it over securely; the owner must change it at
the first sign-in.

### 6. Recreate the migration officers and approvers

Sign in as `admin.one` and request each account under *Users → New account*. Then sign in as
`admin.two` to approve them. The approving administrator sees each new temporary password once.

**Existing batches and audit entries** record the old accounts by name (`officer`, `approver`). To
keep that history attached to a real person, create accounts with exactly those usernames for the
people who used them. Otherwise leave the names as history only; nothing breaks.

If you need the old accounts back immediately (e.g. to finish reviewing a batch), you can create
them from the console instead:

```bash
php bin/console app:user:create officer --name="Migration Officer" --role=officer
php bin/console app:user:create approver --name="Migration Approver" --role=approver
```

### 7. Start the worker and check

- [ ] Switch the **background worker on** again and check it shows *Running*.
- [ ] Open a completed batch: its dates should now be in local time. Something uploaded at 09:00
      Manila time used to show 01:00 and now shows 09:00.
- [ ] Sign in as an officer and an approver and check they see the migration pages. Check that an
      administrator sees only *Users* and *Account audit trail*.
- [ ] The browser console on the sign-in page shows no Content-Security-Policy errors.
- [ ] *Account audit trail* lists the sign-ins you just made.

If the site sits behind a reverse proxy or load balancer, configure trusted proxies (see
[INSTALL.md → Web server](INSTALL.md#7-web-server)). Otherwise the audit trail records the proxy's IP
instead of the user's.

### Rolling back

To return to the previous release:

```bash
php bin/console doctrine:migrations:migrate "DoctrineMigrations\Version20260928100857" --no-interaction
git checkout <previous release>
composer install --no-dev --optimize-autoloader
php bin/console asset-map:compile
php bin/console cache:clear --env=prod
```

The first command undoes both migrations:
- it converts the dates back to UTC, using each row's recorded timezone;
- it removes the `timezone` columns;
- it drops the account tables, **deleting all accounts, their audit trail and change requests**.

Put the `MIGRATION_*_PASSWORD_HASH` values back into `.env.local`.

To keep the account audit trail, restore the backup taken before the upgrade instead.
