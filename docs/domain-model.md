# Domain model

## Core idea: tasks vs occurrences

- A **task** is *what* must be done ("Organize my desk"). It has one logical status: `open`, `completed` or `cancelled`.
- An **occurrence** is *one scheduled attempt* at a task (Mon 15:00–15:15 America/New_York). A task can have many occurrences over time.
- **Carryover is a query, not a copy.** An open task whose past attempts were missed or unanswered shows up in the backlog. Rescheduling it creates a **new occurrence of the same task**. Carryover code never inserts into `tasks`.

## Tables

| Table | Key fields | Invariants |
|---|---|---|
| `users` | id, name, email, password, email_verified_at | Unique email |
| `invitations` | email, token_hash, invited_by, expires_at, accepted_at | Single-use; only the hash is stored |
| `accounts` | id (ULID), name, type=`personal`, created_by | One personal account per user in the MVP |
| `account_memberships` | account_id, user_id, role `owner\|member`, status | Unique `(account_id, user_id)` |
| `user_preferences` | user_id, account_id, timezone, grace_minutes=15, work_windows, quiet_hours, review_times, notification_preferences | Timezone is a valid IANA identifier |
| `tasks` | id, account_id, owner_user_id, title, description, duration_minutes, status, completed_at, cancelled_at | Index `(account_id, owner_user_id, status)`; soft-deleted, never hard-deleted |
| `task_occurrences` | id, account_id, task_id, scheduled_start_at, scheduled_end_at, timezone, state, closed_at, version | UTC instants; `end > start`; index `(account_id, state, scheduled_end_at)` |
| `completion_responses` | account_id, task_id, occurrence_id?, user_id, response, response_at, completed_at, punctuality, channel, idempotency_key | Append-only; unique `idempotency_key` |
| `notification_deliveries` | account_id, user_id, occurrence_id, kind, channel, status, due_at, sent_at, external_message_id, occurrence_version, attempt_count | Unique `(occurrence_id, kind, channel)` |
| `notification_actions` | delivery_id, user_id, action, token_hash, expires_at, consumed_at | Opaque callback tokens; only the hash is stored |
| `telegram_links` | user_id, telegram_user_id, telegram_chat_id, status | Unique `telegram_user_id`; private chats only |
| `pairing_codes` | user_id, code_hash, expires_at, used_at, attempts | Short-lived, single-use |
| `daily_reviews` | account_id, user_id, local_date, kind, status, sent_at | Unique `(user_id, local_date, kind)` |
| `audit_events` | account_id, actor_user_id, subject_type, subject_id, action, metadata | No secrets in metadata |

Every child row that belongs to a tenant carries `account_id`. Services check that it matches the parent's `account_id`, because a plain foreign key doesn't guarantee that.

## States

**Task status:** `open` → `completed` | `cancelled`. Only an explicit **Yes** (any channel) or a web/API completion sets `completed`. Only an explicit user action sets `cancelled`.

**Occurrence state:**

| From | Event | To | Task effect |
|---|---|---|---|
| `scheduled` | end time reached, prompt claimed | `awaiting_response` | none (stays `open`) |
| `scheduled` / `awaiting_response` | **Yes** | `finished` | → `completed` (if still `open`) |
| `scheduled` / `awaiting_response` | **No** | `missed` | stays `open` |
| `awaiting_response` | no answer within `unanswered_after` | `unanswered` | stays `open` |
| any non-terminal | reschedule | (same row, `version`+1, new times) | none; pending deliveries cancelled |
| any non-terminal | cancel | `cancelled` | none |

**Responses:** `yes`, `no`, `partial` (records progress, task stays open), `snooze` (deferred to v0.2).

**Punctuality** is measured against the **planned end**, not the time the message was sent:

- `on_time`: completed at or before `scheduled_end_at`
- `within_grace`: up to and including `scheduled_end_at + grace_minutes`
- `late`: after that

Silence never means completion, and `unanswered` is a different state from `missed` (an explicit No).

## Idempotency & concurrency

- Every mutation carries an idempotency key. For Telegram, the key is the action ID. For the web, it's a per-render UUID. For the API, it's the `Idempotency-Key` header.
- The completion service locks the **task row first, then the occurrence**, always in that order, inside one transaction.
- If the same key is replayed, the service returns the original outcome and writes no new transition or audit row.
