# API v1

Base URL: `/api/v1`. JSON only (`Accept: application/json`).

## Authentication

Personal access tokens (Laravel Sanctum), sent as `Authorization: Bearer <token>`.

```bash
php artisan nunez:token you@example.com --name=shortcuts --ability=tasks:read --ability=tasks:write
php artisan nunez:token you@example.com --list
php artisan nunez:token you@example.com --revoke=<id>
```

| Ability | Grants |
|---|---|
| `tasks:read` | `GET /tasks`, `GET /tasks/{id}` |
| `tasks:write` | `POST /tasks`, `PATCH /tasks/{id}`, `POST /tasks/{id}/cancel` |
| `reviews:read` | reserved for daily reviews (#18) |

Tokens are hashed at rest and shown only once. Every request is scoped to the token owner's own account; IDs belonging to anyone else return `404`.

## Conventions

- **Timestamps** are UTC ISO-8601 (`2026-11-10T00:00:00Z`). Occurrences also return their planning `timezone` and a `local` rendering.
- **Idempotency:** send an `Idempotency-Key` header (≤ 100 chars, `[A-Za-z0-9._:-]`) on `POST`/`PATCH` to make retries safe. The first successful response is replayed for 24 h with `Idempotent-Replayed: true`. Reusing a key with a different body returns `422`. Keys are private to each user.
- **Pagination:** cursor-based, `per_page` 1–100 (default 25). Use `meta.next_cursor` as `?cursor=`.
- **Rate limit:** 120 requests/minute per user (`429` when exceeded).

| Status | Meaning |
|---|---|
| 401 | Missing/invalid token |
| 403 | Token lacks the required ability, or the account is not active/approved/verified |
| 404 | Not found **or not yours** (body: `{"message":"Not found."}`) |
| 409 | Not allowed in the task's current state (e.g. editing a completed task) |
| 422 | Validation error (`{"message": …, "errors": {field: [...]}}`) |
| 429 | Rate limited |

## Endpoints

### `GET /me`
```json
{ "user": { "id": 1, "name": "Alice", "email": "alice@example.test", "timezone": "America/New_York" },
  "account": { "id": "01J…", "name": "Alice", "type": "personal" } }
```

### `GET /tasks?status=open&per_page=25`
`status`: `open` | `completed` | `cancelled` (optional). Returns `{ "data": [Task…], "links": …, "meta": … }`.

### `POST /tasks`
```json
{
  "title": "Clean or organize one area",
  "description": "optional",
  "duration_minutes": 15,
  "occurrence": {
    "starts_at": "2026-11-09T19:00:00-05:00",
    "ends_at":   "2026-11-09T19:15:00-05:00",
    "timezone":  "America/New_York"
  }
}
```
`occurrence` is optional. Datetimes with an offset are used as given; without one (`"2026-11-09 19:00"`) they're interpreted in `timezone`. A block must end after it starts and be ≤ 24 h. The task and its occurrence are created atomically.

`201`:
```json
{ "task": { "id": "01J…", "title": "…", "status": "open", "duration_minutes": 15, … },
  "occurrence": { "id": "01J…", "state": "scheduled",
                  "starts_at": "2026-11-10T00:00:00Z", "ends_at": "2026-11-10T00:15:00Z",
                  "timezone": "America/New_York",
                  "local": { "starts_at": "2026-11-09T19:00:00-05:00", "ends_at": "2026-11-09T19:15:00-05:00" },
                  "version": 1, "closed_at": null } }
```

Prohibited fields (always `422`): `account_id`, `owner_user_id`, `status`, `completed_at`, `cancelled_at`.

### `GET /tasks/{id}`
Task with its `occurrences` (attempt history, oldest first).

### `PATCH /tasks/{id}`
`title`, `description`, `duration_minutes`. Only open tasks (`409` otherwise).

### `POST /tasks/{id}/cancel`
Explicit cancellation; idempotent. Cancels pending (`scheduled`/`awaiting_response`) occurrences and keeps past attempts as history. Completed tasks return `409`.

## Scheduling and responses (#9)

All require `tasks:write`. Times follow the same rules as task creation; **times without an offset are interpreted in the block's `timezone`** (or, for `completed_at`, in your profile timezone), using the DST policy below.

### `POST /tasks/{id}/occurrences`
Schedule another attempt of the **same** task (carryover never duplicates a task).
```json
{ "starts_at": "2026-11-10 19:00", "ends_at": "2026-11-10 19:15", "timezone": "America/New_York" }
```
`201` → Occurrence. `409` if the task is not open.

### `PATCH /occurrences/{id}`
Move an **upcoming** block (`state = scheduled`). Bumps `version`, so notifications queued for the old time are discarded. Blocks that already ended are history: schedule a new attempt instead (`409`).

### `POST /occurrences/{id}/cancel`
Cancel one block; the task stays open. Idempotent.

### `POST /occurrences/{id}/responses`
```json
{ "response": "yes" | "no" | "partial", "completed_at": "optional, yes only", "notes": "optional" }
```
| Response | Effect |
|---|---|
| `yes` | Task → `completed`, block → `finished`, other upcoming blocks of the task → `cancelled`. Punctuality vs. the block's **planned end**: `on_time` (≤ end), `within_grace` (≤ end + your grace, default 15 min), else `late`. |
| `no` | Block → `missed`; task stays **open** (it will show in the backlog). |
| `partial` | Progress note; nothing closes. |
| *(silence)* | Nothing changes; the task stays open. |

`201` with `{response, task, occurrence, replayed:false}`. Send an `Idempotency-Key` header: a retry with the same key returns the original result (`replayed: true`) and never applies twice. A response that can no longer apply (e.g. `no` after the task was completed, or anything on a cancelled task) returns `409`. `completed_at` cannot be in the future.

### `POST /tasks/{id}/complete`
"I did it" outside a specific prompt. Optional `occurrence_id`, `completed_at`, `notes`. Without `occurrence_id`, the most recent started, still-open block is used to classify punctuality (none → `punctuality: null`).

### `POST /tasks/{id}/completion-time`
`{ "completed_at": "2026-11-09T15:05:00Z" }`. Corrects when a completed task was actually done. Appends a new response (history is never rewritten), re-classifies punctuality and is audited.

## DST policy
- A local time that doesn't exist (spring-forward gap, e.g. 02:30 on 2027-03-14 in New York) resolves to the first valid instant after the gap (**03:00**).
- A local time that happens twice (fall-back, e.g. 01:30 on 2026-11-01 in New York) resolves to its **first** occurrence (EDT).

## Today and backlog (#10)

Both require `tasks:read` and use **your profile timezone** for day boundaries. `date` (`YYYY-MM-DD`) defaults to your local today.

### `GET /today?date=2026-11-10`
```json
{ "date": "2026-11-10", "timezone": "America/New_York",
  "blocks": [ Occurrence + "task" … ],            // blocks starting on that local day, any state
  "pending_responses": [ Occurrence + "task" … ], // ended blocks of open tasks still awaiting yes/no
  "backlog_count": 3 }
```

### `GET /backlog?date=2026-11-10&per_page=25`
Carryover is a **query**, not a copy: every **open** task that has at least one attempt that ended **before the start of that local day** and was never resolved (`missed`, `unanswered`, `awaiting_response`, or a `scheduled` block that ended without a prompt). Each task appears **once**, oldest first, until it is completed or cancelled. Weekends and gaps don't matter.
```json
{ "data": [ { "task": { "id": "01J…", "title": "Organize my desk", … },
              "overdue_since": "2026-11-09T15:15:00Z",
              "attempt_count": 2,
              "rescheduled": true, "next_attempt_at": "2026-11-11T19:00:00Z",
              "attempts": [ Occurrence … ] } ],
  "meta": { "total": 3, … }, "date": "2026-11-10", "timezone": "America/New_York" }
```
To retry, `POST /tasks/{id}/occurrences`: the new attempt belongs to the **same** task (it stays in the backlog, flagged `rescheduled`, until resolved).

### Unanswered
Ended blocks with no answer become `unanswered` after **your** `unanswered_after_minutes` (default **12 hours**, configurable 15 min – 7 days) via `nunez:mark-unanswered`, which the scheduler runs every 5 minutes. Unanswered is **not** "no": the task stays open, stays in the backlog, and can still be completed (classified `late`).
