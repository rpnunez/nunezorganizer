# ADR 0002: ULID identifiers for domain models

- Status: Accepted (2026-11-08)

## Decision
User-facing domain models (accounts, tasks, occurrences, responses, deliveries, invitations, audit events) use **ULID primary keys** via Eloquent `HasUlids`. `users` keeps the starter kit's auto-increment ID, which is never exposed in URLs or the API.

## Rationale
- IDs are not enumerable, and ULIDs sort by time, which keeps indexes efficient.
- **IDs are never authorization.** Every lookup is scoped by tenant and checked by a policy. A foreign ID returns **404**, so the response doesn't reveal whether the record exists.
