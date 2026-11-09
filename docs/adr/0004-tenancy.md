# ADR 0004: Tenancy — personal accounts, tenant derived from auth

- Status: Accepted (2026-11-08)

## Decision
- When a user is created, an `accounts` row (`type=personal`) and an `owner` membership are created with it. The schema supports `member` roles, but sharing is **disabled** in the MVP.
- All tenant data lives in a single database. Every tenant-owned table carries `account_id`.
- The **current tenant is resolved only from the authenticated user's active membership** (`TenantContext`, set by middleware). A request-supplied `account_id` is never trusted. It is prohibited in Form Requests and guarded against mass assignment.
- Tenant models use a `BelongsToAccount` trait. The trait adds a global scope and fills `account_id` on create. Policies additionally check `owner_user_id`.
- Queued jobs carry only IDs. They re-check membership and ownership in `handle()`, and tenant context is reset between jobs.
- Every bound route is covered by a cross-tenant "route sweep" test. A foreign ID must return 404.

## References
OWASP Multi-Tenant Security Cheat Sheet.
