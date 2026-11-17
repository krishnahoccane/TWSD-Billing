# Deployment

Final guide (Phase G). Integrations stay in mock mode locally; in production the real Google and Zoho clients are
always used, so they must be configured before go-live.

## Target environment

- Linux server, Nginx + PHP-FPM 8.3 (`pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `intl`, `curl`, `zip`, `redis`)
- MySQL 8 (InnoDB, utf8mb4) per the specification. The database currently provisioned is MariaDB 10.11
  (`DB_CONNECTION=mariadb`); see decision D-01. Restrict port 3306 to the application server's IP and use TLS.
- Redis 7, Supervisor, HTTPS (TLS 1.2+)
- An SMTP/API mail provider (D-13) for password resets and notification e-mail
- Node.js 20+ on the build machine only

## Shared hosting (cPanel Git Version Control)

`vendor/` is not in git and cPanel usually has no Node.js, so:

- `public/build` (compiled CSS/JS) **is committed**. Run `npm run build` and commit before every push that changes views or CSS.
- After each pull, run Composer on the server (cPanel → Terminal):

  ```bash
  cd ~/<repository folder>
  composer install --no-dev --optimize-autoloader
  ```

- **Document root**: cPanel → Domains → the domain → set the document root to `<repository folder>/public`.
  If that is not possible, the repository's root `.htaccess` forwards every request into `public/` and blocks
  directory listings, so source files, docs and storage can never be downloaded.
- **PHP**: cPanel → MultiPHP Manager → PHP 8.2 or 8.3 for the domain; Select PHP Version / PHP extensions →
  enable `pdo_mysql`, `bcmath`, `intl`, `mbstring`, `fileinfo`, `curl`, `zip`, `sodium`.
- **Permissions**: `storage/` and `bootstrap/cache/` writable by your cPanel user (755 folders / 644 files is usual
  on suPHP/LSAPI hosts).
- **Cron** (cPanel → Cron Jobs; no Supervisor on shared hosting). **Without these, nothing queued ever runs** —
  Google sync, mailbox changes, Zoho, billing and e-mail all stay "pending". Use the full path of the PHP CLI that
  matches the site's version (cron's plain `php` is often an older version; check with `ls /opt/cpanel/ea-php8*/root/usr/bin/php`
  or `which php` in Terminal):

  ```
  * * * * * cd /home/<user>/<repository folder> && /opt/cpanel/ea-php83/root/usr/bin/php artisan schedule:run >> storage/logs/cron.log 2>&1
  * * * * * cd /home/<user>/<repository folder> && /opt/cpanel/ea-php83/root/usr/bin/php artisan queue:work --queue=google,zoho,billing,notifications,default --stop-when-empty --max-time=55 >> storage/logs/worker.log 2>&1
  ```

  A long job (e.g. a full directory import) keeps running past 55 s; the queue's `retry_after` (660 s) stops the
  next minute's worker from picking up the same job.

Then open `https://<domain>/install`.

## First deployment — web installer

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

Point the web server at `public/` and open `https://<host>/install`. With no `.env` every page goes to the installer:

1. **Requirements** — PHP version, required/recommended extensions, writable folders, built assets, HTTPS.
2. **Application and database** — name, URL, MySQL/MariaDB connection (tested before continuing), optional Redis
   (tested; only offered when the PHP redis extension is installed — otherwise the database is used for cache,
   sessions and queues).
3. **Super Admin** — display name, e-mail, password (12+ characters, mixed case, number, symbol). If the database
   already has users, no account is created: you must sign in as one of its Super Admins, and may enter the previous
   `APP_KEY` so stored secrets stay readable.
4. **E-mail** — SMTP host, port, encryption, credentials, from address; "Send test e-mail" sends to the Super Admin.
   Can be skipped (mail then goes to the log).

**Install** runs `migrate --force` (never fresh/wipe — existing data is kept) and the roles seeder, creates the
Super Admin, writes a production `.env` from `.env.example` (new `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`,
`WCC_ENFORCE_2FA=true`, encrypted sessions, secure cookies for https URLs), records `platform.installed` in the audit
log and writes `storage/app/installed.lock`. From then on `/install` answers 404. To run it again, remove both `.env`
and the lock file on the server.

Back up the new `.env` immediately (it holds `APP_KEY`), then `php artisan optimize`.

Manual alternative: `cp .env.example .env`, fill it in, `php artisan key:generate`, `php artisan migrate --force`,
`php artisan db:seed --class=RolesAndPermissionsSeeder --force`, `php artisan wcc:create-super-admin`.

## Production `.env` essentials (written by the installer)

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<host>
SESSION_DRIVER=redis
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
CACHE_STORE=redis
QUEUE_CONNECTION=redis
WCC_ENFORCE_2FA=true
MAIL_MAILER=smtp                # plus MAIL_HOST / MAIL_USERNAME / MAIL_PASSWORD / MAIL_FROM_ADDRESS
```

Google and Zoho settings: see the go-live checklists in
[GOOGLE_WORKSPACE_INTEGRATION.md](GOOGLE_WORKSPACE_INTEGRATION.md) and [ZOHO_INTEGRATION.md](ZOHO_INTEGRATION.md)
(including `ZOHO_WEBHOOK_SECRET`). Never set `WCC_DEV_PASSWORD` and never run `LocalDevelopmentSeeder` in
production (it refuses to run).

## Each release

```bash
php artisan down
git pull && composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force   # adds new permissions; keeps role customisations
php artisan optimize
php artisan queue:restart
php artisan up
```

## Workers and scheduler

Supervisor program:

```
[program:wcc-worker]
command=php /var/www/wcc/artisan queue:work redis --queue=google,zoho,billing,notifications,default --tries=5 --max-time=3600
numprocs=2
autostart=true
autorestart=true
stopwaitsecs=120
user=www-data
```

Every queue must be listed: mailbox changes (`google`), Zoho sync (`zoho`), billing runs (`billing`) and
notification e-mail (`notifications`) all stay pending without a worker.

Cron: `* * * * * cd /var/www/wcc && php artisan schedule:run >> /dev/null 2>&1`

Scheduled work (`routes/console.php`): Google account sync (15 min) and reconciliation (30 min), domain import
(6 h), full user import (02:30), the monthly billing run (1st, 03:00, auto-issue), Zoho reconciliation (hourly at
:20), overdue reminders (09:00) and a one-minute scheduler heartbeat shown on **System → Health**.

## Health and monitoring

- `GET /up` — Laravel liveness endpoint for the load balancer.
- **System → Health** (`system.health.view`): database latency, cache, queue driver and oldest queued job, failed
  jobs, scheduler heartbeat, Google mode and last sync, Zoho connection, differences and last reconciliation, debug
  mode, 2FA enforcement, secure cookie and free disk space. Production misconfigurations show as failures.
- **System → Queues** (`queues.monitor`): pending jobs per queue and failed jobs with their first error line;
  retry or discard (both audited).
- Integration failures are also notified in-app/e-mail to those who manage the integration, at most once an hour
  per kind.

## Go-live checklist

1. Production `.env` as above; `APP_DEBUG=false`; `WCC_ENFORCE_2FA=true`; HTTPS with HSTS.
2. Rotate the database password that was shared during development; restrict port 3306 to the app server and use TLS.
3. Revoke `UPDATE`/`DELETE` on `audit_logs` from the application's database user.
4. Change the initial Super Admin password to one meeting the 12-character policy and enrol 2FA.
5. Configure mail (D-13) and send a test password-reset.
6. Connect Google (service account, D-12) and run a read-only import; check the Unassigned queue.
7. Connect Zoho Books (OAuth, organisation, tax, item, deposit account, webhook) and run "Test connection".
8. Supervisor worker and cron running; System Health shows the scheduler heartbeat.
9. Backups configured and one restore tested.
10. Decide audit-log retention (D-22).

## Backups

- Database: nightly full dump + binary logs (point-in-time recovery), encrypted, stored off-server, 30-day retention.
- `.env` / `APP_KEY`, the Google service-account key and the Zoho client secret: in a secrets manager, not in the
  database backup. Losing `APP_KEY` makes 2FA secrets and integration tokens unreadable.
- Test a restore at least quarterly.
