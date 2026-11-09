# CLAUDE.md — mirrors AGENTS.md (keep the two in sync)

You are working on **NunezOrganizer** (`rpnunez/nunezorganizer`), a standalone, multi-user Laravel/MariaDB accountability app. This repository is **public**.

## Read first
1. `docs/architecture.md`, `docs/domain-model.md`, `docs/adr/*`
2. The GitHub issue(s) you were assigned. Each issue body contains the implementation plan and acceptance criteria. Implement those criteria.
3. Existing tests near the code you change.

## Stack & commands
- PHP **^8.4**, Laravel **13**, Livewire 4 + Flux, Fortify, MariaDB **10.11**. Tests run on MariaDB, never SQLite.
- Docker: `docker compose up -d`, then prefix commands with `docker compose run --rm app`.

| Purpose | Command |
|---|---|
| Install | `composer install && npm ci` |
| Build assets | `npm run build` |
| Migrate | `php artisan migrate` |
| Tests | `php artisan test` |
| Code style (fix / check) | `vendor/bin/pint` / `vendor/bin/pint --test` |
| Static analysis | `vendor/bin/phpstan analyse` |

CI (`.github/workflows/ci.yml`) runs: migrate → rollback → migrate, Pint `--test`, PHPStan, tests. Run the same commands before you say you're done, and **report the actual results**.

## Non-negotiable rules
- **MariaDB is the only source of truth.** Do not add Google Calendar, ChatGPT or other external-service dependencies unless the issue explicitly asks for them.
- **Tenant scope comes from authentication only.** Never trust `account_id` (or owner IDs) from request input. Use `TenantContext`, the `BelongsToAccount` trait and policies. A foreign ID must return **404**.
- **Every new bound route needs a cross-tenant test** (Alice cannot see or touch Bob's data). Use the helpers in `tests/Concerns`.
- **State changes go through domain services**, inside a DB transaction with row locks (task first, then occurrence) and an idempotency key. Retries and duplicate callbacks must be no-ops.
- **Store times as UTC instants.** Evaluate local times against the user's IANA timezone. Follow the DST policy in ADR 0003.
- **Queued jobs carry IDs only** and re-check authorization and current state in `handle()`.
- **No secrets** in code, fixtures, logs, issues or PR text. Use fakes for Telegram. Never invent credentials.
- Use migrations, factories, Form Requests, policies, API Resources, and feature/unit tests for all new behavior.

## Scope & PR hygiene
- Work only on the assigned issue(s), on one branch per PR slice (see the roadmap issue #27). Keep diffs small and reviewable.
- Do not modify unrelated code, GitHub settings, production infrastructure or external services. **Do not deploy.**
- Do not merge PRs unless the owner explicitly says to.
- Fill in `.github/pull_request_template.md`: linked issue, summary, test evidence (commands + results), migration notes, security checklist.
- If you're blocked (missing credentials, an owner decision, VPS access), use fakes, document how to validate later, and say so.
