## Linked issue(s)
Closes #

## Summary
<!-- What changed and why. Keep PRs to one issue / PR slice. -->

## Test evidence
<!-- Commands you ran and their actual results. -->
- [ ] `vendor/bin/pint --test`
- [ ] `vendor/bin/phpstan analyse`
- [ ] `php artisan test` (MariaDB)

## Migrations
- [ ] None, **or**: new/changed migrations listed here, `down()` verified (CI does migrate → rollback → migrate)

## Security checklist
- [ ] Tenant scope comes from auth (`TenantContext`), not request input; cross-tenant tests added for new routes
- [ ] State changes are transactional and idempotent (retries / double submits are harmless)
- [ ] No secrets, tokens or personal data in code, fixtures, logs or this PR
- [ ] Times stored as UTC; local-time logic uses the user's IANA timezone

## Notes for reviewer
