# NunezOrganizer

A standalone, mobile-first, multi-user accountability app. You schedule small, time-bound commitments. When each block ends, a private Telegram check-in asks **Yes / No**. The answer is recorded honestly, and unfinished work stays visible in your backlog until it's done.

> Status: early development (milestone **M0 · Repo & CI Foundation**). See the pinned [roadmap issue](https://github.com/rpnunez/nunezorganizer/issues/27).

## Stack

| Component | Version | Notes |
|---|---|---|
| PHP | **8.4 minimum** (`^8.4`) | Production: LSPHP 8.4 on OpenLiteSpeed |
| Laravel | **13.x** | Official Livewire starter kit (Livewire 4, Flux, Fortify) |
| Database | **MariaDB 10.11 LTS** (dev/CI) | Authoritative for dev, CI and production; tests do not use SQLite |
| Node | 22+ | Vite (vite-plus) + Tailwind 4 asset build |
| Queue / cache / sessions | `database` driver | Redis only if later justified |

All timestamps are stored in **UTC**. Each user's IANA timezone drives local-time display and scheduling.

## Local development (Docker)

The Docker stack provides PHP 8.4, MariaDB and Mailpit, so the host PHP version doesn't matter.

```bash
cp .env.example .env
docker compose build
docker compose up -d                 # app on http://localhost:8000, Mailpit on http://localhost:8025
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
npm ci && npm run dev                # or: npm run build
```

Optional queue worker + scheduler: `docker compose --profile workers up -d`.

MariaDB is forwarded to host port `33306` (override with `FORWARD_DB_PORT`). The init script creates a second database, `nunezorganizer_test`, for the test suite.

### Native setup (no Docker)

Requires PHP 8.4 with `pdo_mysql`, `intl`, `zip`, `bcmath`, `mbstring`, Composer 2, Node 22+, and MariaDB 10.11+. Create the `nunezorganizer` and `nunezorganizer_test` databases (utf8mb4), set `DB_HOST=127.0.0.1` in `.env`, then:

```bash
composer setup
composer dev
```

### Windows + XAMPP (verified setup)

XAMPP ships PHP 8.2, which is too old for Laravel 13. Keep it, and put the official **PHP 8.4 NTS x64** build from [windows.php.net](https://windows.php.net/download/) next to it, e.g. in `C:\xampp\php84`. Copy `php.ini-development` to `php.ini` and enable `curl`, `fileinfo`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `sodium`, `zip`, with `memory_limit = 1G`. XAMPP's bundled **MariaDB 10.4** (port 3306) works for development.

```sql
-- as MariaDB root (XAMPP: C:\xampp\mysql\bin\mysql.exe -u root)
CREATE DATABASE nunezorganizer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE nunezorganizer_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nunezorganizer'@'localhost' IDENTIFIED BY 'secret';
CREATE USER 'nunezorganizer'@'127.0.0.1' IDENTIFIED BY 'secret';
GRANT ALL ON nunezorganizer.* TO 'nunezorganizer'@'localhost', 'nunezorganizer'@'127.0.0.1';
GRANT ALL ON nunezorganizer_test.* TO 'nunezorganizer'@'localhost', 'nunezorganizer'@'127.0.0.1';
```

Then, with `DB_HOST=127.0.0.1` and `MAIL_MAILER=log` in `.env`:

```bash
C:/xampp/php84/php.exe C:/ProgramData/ComposerSetup/bin/composer.phar install
C:/xampp/php84/php.exe artisan key:generate
C:/xampp/php84/php.exe artisan migrate --seed
npm ci && npm run build
C:/xampp/php84/php.exe artisan serve        # http://127.0.0.1:8000
```

> GitHub Actions is currently disabled for this repository, so these local runs are how PRs are verified. Use the "Run CI locally" steps below with `C:/xampp/php84/php.exe` in place of `php`.

## API

Token-authenticated JSON API at `/api/v1`. See [docs/api.md](docs/api.md).

```bash
php artisan nunez:token you@example.com --ability=tasks:read --ability=tasks:write
```

## Users and invitations

Registration is **invite-only** by default (`NUNEZ_REGISTRATION_MODE=invite`).

```bash
php artisan nunez:invite person@example.com            # prints a single-use link (expires in 7 days)
php artisan nunez:invite person@example.com --days=2
php artisan nunez:user:approve person@example.com       # only needed in "approval" mode
php artisan db:seed                                     # local/testing only: alice@example.test / bob@example.test (password: "password")
```

Modes: `invite` (default; `/register` returns 404 unless a valid invitation is presented), `approval` (anyone can sign up, but can't use the app until approved), `open` (public sign-up; not recommended before launch).

## Tests and quality checks

```bash
php artisan test        # uses MariaDB database nunezorganizer_test (forced in phpunit.xml)
composer lint           # Laravel Pint (fix)
composer lint:check     # Pint (check only)
composer types:check    # PHPStan / Larastan
```

With Docker, prefix each one with `docker compose run --rm app`.

### Run CI locally

These are the same steps as `.github/workflows/ci.yml` (prefix each with `docker compose run --rm app` when using Docker):

```bash
composer install && npm ci && npm run build
php artisan migrate --force && php artisan migrate:rollback --force && php artisan migrate --force
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test
```

## Documentation

- [Architecture](docs/architecture.md) · [Domain model](docs/domain-model.md) · [ADRs](docs/adr/)
- [AGENTS.md](AGENTS.md): rules for coding agents and contributors
- [SECURITY.md](SECURITY.md): private vulnerability reporting

## Health endpoints

- `GET /healthz` returns `{"status":"ok"}` (liveness, no dependencies checked)
- `GET /readyz` returns `{"status":"ok"}`, or `503 {"status":"unavailable"}` if the database is unreachable
- `GET /up` is Laravel's built-in health route

Neither endpoint exposes versions, environment names or error details.

## Security

- Never commit `.env` files, Telegram tokens or credentials. This repository is **public**.
- Registration is invite-only. Invitation tokens are single-use, expire, and are stored only as SHA-256 hashes.
- Password-reset responses are identical for known and unknown emails (no account enumeration).
