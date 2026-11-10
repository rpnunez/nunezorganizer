# Security threat model (P14b)

This review covers the Laravel web UI, Sanctum API, Telegram webhook and queue, and database/backups. The deployment controls in issue #21 remain a separate release gate. Recheck this model after changing authentication, tenancy, external integrations, or hosting.

| Surface | Threat | Current control | Verification / remaining risk |
| --- | --- | --- | --- |
| Login and password reset | Credential stuffing and reset mail floods | Fortify login limiter (5/minute); reset POST limiter (5/minute per IP and email, 20/minute per IP); reset tokens expire | Feature tests cover reset throttling. Distributed attacks across IPs still require monitoring. |
| Session web UI | CSRF, cross-tenant access, XSS | Laravel CSRF middleware; authenticated tenant context and policies; escaped Blade output; CI guard rejects raw Blade echo | Cross-tenant feature tests cover task/API paths. Review any future HTML/JS output manually. |
| Browser responses | Script injection, clickjacking, MIME confusion, downgrade | CSP in production; X-Frame-Options, nosniff, referrer and permissions policies; HSTS on secure production requests | CSP still permits inline script and eval for current Livewire/Flux compatibility. Tighten with nonces after a browser compatibility pass. Verify HTTPS and proxy trust in staging. |
| Sanctum API | Stolen token or excessive access | Auth, verified user, token abilities, tenant policies, API limiter, idempotency keys | Pairing has its own 5/minute limiter. Rotate a compromised token immediately. |
| Telegram webhook | Forged/replayed updates, callback tampering, oversized payload | Secret route key and constant-time secret header check; size limit; update ID deduplication; one-time callback tokens; IP rate limit | Rotate bot and webhook secrets together after suspected disclosure; provider delivery is outside our control. |
| Queue | Stale authorization and duplicate effects | Jobs use IDs and re-check state; database transactions and unique/idempotency controls | Monitor failed jobs and delivery retries. |
| Database and backups | Tenant data exposure, credential theft, lingering erased data | Separate DB credentials; operator-only CLI export/deletion; personal account cascade; encrypted backup procedure in #21 | Production restore and retention require #21 deployment validation. Exports are sensitive temporary files. |

## Review checklist

- [x] Login, reset, pairing, API and webhook rate limits inspected; reset and pairing limits added.
- [x] Web CSRF middleware and tenant-scoped API routes inspected.
- [x] Raw Blade echo removed; CI guard added. Dynamic JavaScript still needs review when changed.
- [x] Production security headers added with documented CSP compatibility exception.
- [x] Sanctum abilities, webhook header, route key, update deduplication and callback tokens inspected.
- [x] Operator export/deletion paths and cross-tenant data tests added.
- [ ] Validate HTTPS, HSTS, headers, backups and restore against the actual staging host after #21 is deployed.

Do not mark the staging item complete from local tests alone. Record any newly discovered vulnerability as a separate issue with severity and reproduction steps, excluding secrets and personal data.
