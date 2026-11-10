# Installing NunezOrganizer

This guide covers every supported way to run NunezOrganizer, on any host:

| You want to… | Use | Section |
|---|---|---|
| Develop or test on Windows with XAMPP | XAMPP MariaDB + separate PHP 8.4 CLI + Node | [Windows XAMPP MariaDB](#windows-xampp-mariadb) |
| Develop natively elsewhere | PHP 8.4 + MariaDB + Node | [Development (native)](#development-native) |
| Use optional containers | Docker (nginx, PHP-FPM + Xdebug, MariaDB, Vite, Mailpit, phpMyAdmin) | [Development (Docker)](#development-docker) · full guide: [development.md](development.md) |
| Run it on a server, simply | `setup.php --env=production` in one directory | [Production A: in place](#production-a-in-place) |
| Run it on a server with zero-downtime releases, backups and rollback | `deploy/` scripts | [Production B: releases](#production-b-zero-downtime-releases) |

A worked example on a Webuzo + OpenLiteSpeed host is in [runbook.md](runbook.md).

## Requirements

| Component | Version | Notes |
|---|---|---|
| PHP | **8.4+** | Extensions: `bcmath ctype curl dom fileinfo filter intl mbstring openssl pdo_mysql session tokenizer xml`; `pcntl` on Linux for queue workers |
| Composer | 2.x | |
| MariaDB | **10.6+** (10.11 LTS recommended) | utf8mb4. MySQL 8 likely works, but it isn't tested |
| Node.js | **22.18+** | Only to build assets (Vite / vite-plus). The build downloads web fonts from `fonts.bunny.net` |
| Web server | nginx, Apache 2.4, or OpenLiteSpeed | Document root = the app's `public/` directory |
| Cron + a process manager | cron; systemd or supervisor | The scheduler runs every minute; one queue worker |
| Mail | an SMTP server or a local MTA | Email verification and password reset |

Times are stored in UTC, and the database connection is forced to `+00:00`. The server's own timezone doesn't matter.

## The setup script

`setup.php` is the single entry point for every install type. It needs only PHP 8.4; `vendor/` doesn't have to exist yet. It's safe to re-run, because each step checks what's already done.

```bash
php setup.php                       # local development (default)
php setup.php --env=production      # in-place production install
php setup.php --check               # report problems, change nothing
php setup.php --help                # every option
```

Wrappers: `bin/setup` (Linux/macOS) and `bin\setup.cmd` (Windows). Both honour a `PHP` environment variable pointing at a specific PHP binary.

| Step | Local | Production |
|---|---|---|
| Requirements (PHP, extensions, Composer, Node, writable dirs) | ✓ | ✓ (also `pcntl`) |
| `composer install` | with dev packages | `--no-dev --optimize-autoloader` |
| `.env` | from `.env.example`; native installs get `DB_HOST=127.0.0.1`, `MAIL_MAILER=log` | from `deploy/templates/env/production.env.tpl`, mode 600, validated |
| `APP_KEY` | generated if empty | generated if empty |
| Migrations | `migrate` | `migrate --force` **only** after you confirm, or with `--force` |
| Demo users | alice@example.test / bob@example.test (password `password`) | never |
| Assets | `npm ci && npm run build` (skipped in Docker: the `node` service runs Vite) | `npm ci && npm run build` |
| Caches | `config:clear` | `optimize` |

The production DB password is read from the `NUNEZ_DB_PASSWORD` environment variable, or prompted for with the input hidden. It's never accepted as a command-line flag, where other users could see it in the process list.

## Development (Docker)

Needs Docker Desktop (Windows/macOS) or Docker Engine with the Compose plugin (Linux).

```bash
git clone https://github.com/rpnunez/nunezorganizer.git && cd nunezorganizer
cp .env.example .env                      # edit NUNEZ_*_PORT if any default port is taken
docker compose up -d --build
docker compose exec app php setup.php
```

| URL | What |
|---|---|
| http://localhost:8480 | the app (log in as alice@example.test / `password`) |
| http://localhost:8481 | phpMyAdmin |
| http://localhost:8482 | Mailpit, which catches every email the app sends |
| http://localhost:5480 | Vite dev server (pages load assets from it; live reload) |
| `127.0.0.1:33480` | MariaDB for host tools (user and password from `.env`) |

Xdebug, VS Code, running tests in containers and troubleshooting: see [development.md](development.md).

## Development (native)

Install PHP 8.4 with the extensions above, plus Composer, Node 22.18+ and MariaDB 10.6+. Then create the databases as the MariaDB root user:

```sql
CREATE DATABASE nunezorganizer      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE nunezorganizer_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nunezorganizer'@'localhost' IDENTIFIED BY 'secret';
CREATE USER 'nunezorganizer'@'127.0.0.1' IDENTIFIED BY 'secret';
GRANT ALL ON nunezorganizer.*      TO 'nunezorganizer'@'localhost', 'nunezorganizer'@'127.0.0.1';
GRANT ALL ON nunezorganizer_test.* TO 'nunezorganizer'@'localhost', 'nunezorganizer'@'127.0.0.1';
```

```bash
php setup.php          # Windows: bin\setup.cmd   (set PHP=C:\path\to\php84\php.exe if php isn't 8.4)
php artisan serve      # http://127.0.0.1:8000  (or: composer dev, for server + queue + Vite together)
```

The tests always use the `nunezorganizer_test` database, which `phpunit.xml` forces.

## Windows XAMPP MariaDB

XAMPP can supply **MariaDB** while a separate PHP 8.4 CLI runs Laravel. XAMPP's bundled PHP does not need to be replaced. Start MySQL in the XAMPP Control Panel, then create the two databases and local app user using the SQL in [Development (native)](#development-native). Keep `DB_HOST=127.0.0.1` and use the XAMPP MariaDB port (normally `3306`).

In PowerShell, put the PHP 8.4 directory first on `Path` so Composer and Artisan use the same PHP version. The PHP 8.4 installation needs `pdo_mysql` and the other extensions in [Requirements](#requirements).

```powershell
$php84Dir = 'C:\path\to\php-8.4'
$env:Path = "$php84Dir;$env:Path"
$env:PHP = Join-Path $php84Dir 'php.exe'
& $env:PHP -v
& $env:PHP setup.php --check
& $env:PHP setup.php --db-host=127.0.0.1
& $env:PHP artisan test --compact
& $env:PHP vendor/bin/pint --test
& $env:PHP -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

The local test suite uses the separate `nunezorganizer_test` schema. The Linux deployment-script suite (`bash deploy/tests/run.sh`) requires Linux file permissions and tools such as `flock`; Git Bash on Windows does not model those, so run that suite on a Linux host or CI. No Docker service is required for the native workflow.

## Production A: in place

The whole app lives in one directory, for example `/var/www/nunezorganizer`, and you update it with `git pull` + `setup.php`. This is the simplest option. The cost is a few seconds of mixed old/new code during updates.

1. **Server prerequisites.** Install PHP 8.4-FPM, Composer, Node 22.18+, MariaDB and a web server, then check what's still missing:
   ```bash
   php setup.php --check
   ```
2. **Database and user** (as the MariaDB root user). Use a strong password, and grant on this database only:
   ```sql
   CREATE DATABASE nunezorganizer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'nunezorganizer'@'localhost' IDENTIFIED BY '<strong password>';
   CREATE USER 'nunezorganizer'@'127.0.0.1' IDENTIFIED BY '<strong password>';
   GRANT ALL ON nunezorganizer.* TO 'nunezorganizer'@'localhost', 'nunezorganizer'@'127.0.0.1';
   ```
3. **Code and setup.** Run these as the user that will own the files, not root:
   ```bash
   git clone https://github.com/rpnunez/nunezorganizer.git /var/www/nunezorganizer
   cd /var/www/nunezorganizer
   php setup.php --env=production --app-url=https://organizer.example.com
   ```
   It prompts for the DB password and asks before running migrations. For automation, add `-n --force` and set `NUNEZ_DB_PASSWORD`.
4. **Web server**: point the document root at `/var/www/nunezorganizer/public`. See [Web server](#web-server).
5. **Scheduler and queue worker**: see [Scheduler and queue](#scheduler-and-queue).
6. **Mail**: the generated `.env` uses `MAIL_MAILER=sendmail` (the server's local MTA). If there's no MTA, switch to `smtp` and fill in `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME` and `MAIL_PASSWORD`.
7. **First account**: `php artisan nunez:invite you@example.com` prints a single-use sign-up link.

**Updating:**

```bash
cd /var/www/nunezorganizer
php artisan down --retry=15
git pull
php setup.php --env=production -n --force
php artisan up && php artisan queue:restart
```

## Production B: zero-downtime releases

`deploy/` builds every release in its own directory and switches over by swapping a `current` symlink atomically. Releases with pending migrations are blocked until you approve them, and a release that fails its health check rolls back automatically. You also get encrypted nightly backups with a restore drill, a cron health check, and one-command rollback. The document root becomes `<APP_ROOT>/current/public`.

```bash
deploy/audit/vps-audit.sh --domain organizer.example.com          # read-only: tells you the paths to use
cp deploy/deploy.env.example /etc/nunezorganizer/deploy.env        # then edit: user, domain, PHP path, web server
sudo -u <app-user> deploy/bin/bootstrap-layout.sh                  # dirs + shared/.env (APP_KEY generated)
deploy/bin/provision-database.sh --apply                           # as root: DB + least-privilege users
deploy/bin/render-templates.sh --out /root/nunez-build              # cron, systemd, sudoers, web server configs
deploy/bin/install-system-files.sh --build /root/nunez-build --apply
sudo -u <app-user> deploy/bin/release.sh --migrate                 # first release
```

[runbook.md](runbook.md) covers the full procedure with every command: releases, rollback, backups and the restore drill, and monitoring. It uses a Webuzo + OpenLiteSpeed host as the example. On other stacks, use the matching web server template below.

## Web server

Rendered from `deploy/templates/` by `deploy/bin/render-templates.sh`, using the values in `deploy.env`:

| Stack | Template | Notes |
|---|---|---|
| nginx + PHP-FPM | `nginx/nunezorganizer.conf` + `php-fpm/nunezorganizer.conf` | HTTP→HTTPS (ACME challenges allowed), HSTS and security headers, `$realpath_root` so symlinked releases are cached separately, dotfiles denied |
| Apache 2.4 + PHP-FPM | `apache/nunezorganizer.conf` + `php-fpm/nunezorganizer.conf` | Needs `mod_rewrite`, `mod_ssl`, `mod_headers`, `mod_proxy_fcgi`; `AllowOverride All` for `public/.htaccess` |
| OpenLiteSpeed | `openlitespeed/vhconf.conf` + `httpd_config.snippet.conf` | On panel-managed hosts (Webuzo, CyberPanel), configure through the panel instead; see [runbook.md](runbook.md) |

For in-place installs (Production A), replace `<APP_ROOT>/current/public` with your directory's `public/`. Whatever the server:
- PHP must run as the user that owns the files, never as another site's user.
- Only `public/` is web-reachable.
- `.env`, `storage/` and `vendor/` must never be served.

Check: `curl -s -o /dev/null -w '%{http_code}' https://<domain>/.env` must not return `200`.

## Scheduler and queue

| What | How |
|---|---|
| Scheduler (reminders, notification dispatch, carryover) | cron, every minute, as the app user: `* * * * * cd <app dir> && php artisan schedule:run >> /dev/null 2>&1` |
| Queue worker (`notifications`, then `default`) | systemd unit (`deploy/templates/systemd/`) or supervisor (`deploy/templates/supervisor/`): `php artisan queue:work database --queue=notifications,default --max-time=3600` |
| After each update | `php artisan queue:restart`, so workers load the new code |

## Health checks

- `GET /healthz`: liveness, always `200` while PHP is serving.
- `GET /readyz`: `200` when the database is reachable, otherwise `503` with no details.
- `deploy/bin/healthcheck.sh` combines those with queue, disk and backup-age checks for cron alerts.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| `setup.php` says PHP is too old | `php` on PATH isn't 8.4. Call the 8.4 binary explicitly (`/usr/bin/php8.4 setup.php`) or set `PHP=` for the wrappers |
| `Cannot connect to the database` with `[1049]` | The database doesn't exist yet; setup prints the SQL to create it |
| 500 error, `tempnam(): file created in the system's temporary directory` | `storage/` isn't writable by the PHP user. `chown -R <php user> storage bootstrap/cache` |
| Asset build fails with `fetch failed` | The build downloads fonts from `fonts.bunny.net`; allow outbound HTTPS to it |
| Pages unstyled in production | `public/build/manifest.json` missing: run `npm ci && npm run build`. In dev, make sure `public/hot` isn't left over after stopping Vite |
| Emails never arrive | `MAIL_MAILER=log`, or the MTA lacks SPF/DKIM for the From domain |
