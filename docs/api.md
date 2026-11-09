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

### Coming next
Completion (`POST /tasks/{id}/complete`), occurrence reschedule and responses (#9), `GET /today` and `GET /backlog` (#10).
