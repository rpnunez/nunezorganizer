# NunezOrganizer

A standalone, mobile-first, multi-user accountability app. You schedule small, time-bound commitments. When each block ends, a private Telegram check-in asks **Yes / No**. The answer is recorded honestly, and unfinished work stays visible in your backlog until it's done.

> Status: early development (milestone **M0 · Repo & CI Foundation**). See the pinned [roadmap issue](https://github.com/rpnunez/nunezorganizer/issues/27).

## Stack

| Component | Version | Notes |
|---|---|---|
| PHP | **8.4 minimum** (`^8.4`) | Production: LSPHP 8.4 on OpenLiteSpeed |
| Laravel | **13.x** | Official Livewire starter kit (Livewire 4, Flux, Fortify) |
| Database | **MariaDB 10.11 LTS** (dev/CI), 10.6+ supported | Authoritative for dev, CI and production; tests do not use SQLite |
| Node | 22.18+ | Vite (vite-plus) + Tailwind 4 asset build |
| Queue / cache / sessions | `database` driver | Redis only if later justified |

All timestamps are stored in **UTC**. Each user's IANA timezone drives local-time display and scheduling.

## Getting started

**Docker (recommended for development):** PHP 8.4 + Xdebug, nginx, MariaDB, Vite with live reload, a queue worker, the scheduler, Mailpit and phpMyAdmin. Nothing else is needed on your machine.

```bash
cp .env.example .env                  # host ports: NUNEZ_*_PORT (defaults 8480, 8481, 8482, 5480, 33480)
docker compose up -d --build
docker compose exec app php setup.php
```

Then open http://localhost:8480 and log in as `alice@example.test` / `password`. Mailpit runs on :8482 and phpMyAdmin on :8481. Xdebug + VS Code, tests and troubleshooting: **[docs/development.md](docs/development.md)**.

**Any other machine or server:** `php setup.php` (development) or `php setup.php --env=production` (an in-place server install) works anywhere PHP 8.4 runs. Run `php setup.php --check` to see what's missing. Production with zero-downtime releases, backups and rollback uses the `deploy/` tooling. Everything is in **[docs/install.md](docs/install.md)**. [docs/runbook.md](docs/runbook.md) is a worked example on a Webuzo + OpenLiteSpeed host.

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

With Docker, prefix each one with `docker compose exec app`.

### Run CI locally

These are the same steps as `.github/workflows/ci.yml` (prefix each with `docker compose exec app` when using Docker):

```bash
composer install && npm ci && npm run build
php artisan migrate --force && php artisan migrate:rollback --force && php artisan migrate --force
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test
```

## Documentation

- [Install](docs/install.md) · [Development with Docker](docs/development.md) · [Reference deployment (Webuzo + OLS)](docs/runbook.md)
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
