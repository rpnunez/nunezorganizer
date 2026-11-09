# Architecture

NunezOrganizer is a **modular monolith**: one Laravel 13 application, one MariaDB database, one scheduler, and database-backed queue workers. See [ADR 0001](adr/0001-modular-monolith.md).

## Source of truth

**MariaDB is the only system of record** for tasks, scheduled occurrences, responses, carryover and progress. Telegram, and later Google Calendar, ChatGPT and other channels, are *adapters*. They never own state, and the core flows (create, schedule, complete, carryover) must keep working when every adapter is offline.

## Runtime shape

```text
Web (Blade + Livewire + Flux)          Telegram private chat
            |                                   |
   Authenticated routes (session)        HTTPS webhook (secret header)
   API v1 (Sanctum tokens)                      |
            +--------- Application services ----+
                     |          |           |
               Tasks/Plans  Responses  Notifications
                     |          |           |
            Tenant context + owner policies (authorization)
                     |
                  MariaDB  <---- Scheduler (every minute) ----> DB queue workers
                                                                    |
                                                    NotificationChannel contract
                                                                    |
                                                            TelegramChannel
```

## Code layout (target)

| Path | Responsibility |
|---|---|
| `app/Domain/Accounts` | `TenantContext`, `BelongsToAccount` trait, account/preference actions, tenant-aware job middleware |
| `app/Domain/Audit` | `Audit::record()` and the secrets `Redactor` |
| `app/Models` | All Eloquent models (kept in the Laravel default location for factories/discovery) |
| `app/Domain/Tasks` | Task model, task lifecycle services |
| `app/Domain/Scheduling` | Occurrences, completion/punctuality, backlog & today queries |
| `app/Domain/Notifications` | Delivery outbox, `NotificationChannel` contract, dispatch jobs |
| `app/Domain/Reviews` | Daily reviews, weekly stats, routine progression |
| `app/Integrations/Telegram` | Telegram client, webhook handlers, `TelegramChannel` |
| `app/Http` | Controllers, Form Requests, API Resources, middleware |
| `app/Policies` | Laravel policies (owner-within-tenant) |
| `resources/views/pages` | Livewire single-file page components |

Domain services hold business rules. Controllers, Livewire components and Telegram handlers are thin, and every channel calls the **same** service methods. That way web, API and Telegram responses follow identical rules.

## Cross-cutting rules

1. **Tenant scope comes from authentication**, never from request input ([ADR 0004](adr/0004-tenancy.md)).
2. **UTC in the database, IANA timezones for local meaning** ([ADR 0003](adr/0003-time-handling.md)).
3. **State changes run in transactions with row locks and idempotency keys.** Retries and duplicate Telegram deliveries must be harmless.
4. **Database uniqueness constraints are the last line of defence** against duplicate prompts and reviews. In-memory locks are not enough.
5. **Queued jobs carry IDs only** and re-check authorization and current state when they run.
6. **Audit** every state transition and security-sensitive change in `audit_events`. Never log secrets or tokens.

## Scheduling & delivery (M4+)

- OS cron runs `php artisan schedule:run` every minute.
- `nunez:dispatch-due` claims occurrences whose `scheduled_end_at <= now()` by inserting a unique `notification_deliveries` row, then queues a send job.
- The send job re-reads the occurrence. It skips if the occurrence is completed, cancelled or rescheduled (`version` changed), or if the user has paused or unlinked notifications. Otherwise it calls the channel.
- Telegram callbacks are matched by an opaque token (only its hash is stored). The handler checks the Telegram identity → linked user, then calls the completion service.

Delivery is **minute-level best effort**, not hard real-time.

## Deployment (M6)

The app runs as a separate virtual host on the DevStackTips VPS: AlmaLinux 8.10, **OpenLiteSpeed + LSPHP 8.4**, MariaDB. It has its own Unix user, database user, logs, backups and supervised queue worker. It shares nothing with the WordPress install. See issue #21.
