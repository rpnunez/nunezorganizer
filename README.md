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

## Tests and quality checks

```bash
php artisan test        # uses MariaDB database nunezorganizer_test (forced in phpunit.xml)
composer lint           # Laravel Pint (fix)
composer lint:check     # Pint (check only)
composer types:check    # PHPStan / Larastan
```

With Docker, prefix each one with `docker compose run --rm app`.

## Health endpoints

- `GET /healthz` returns `{"status":"ok"}` (liveness, no dependencies checked)
- `GET /readyz` returns `{"status":"ok"}`, or `503 {"status":"unavailable"}` if the database is unreachable
- `GET /up` is Laravel's built-in health route

Neither endpoint exposes versions, environment names or error details.

## Security

- Never commit `.env` files, Telegram tokens or credentials. This repository is **public**.
- Registration is invite-only (see issue #4).
