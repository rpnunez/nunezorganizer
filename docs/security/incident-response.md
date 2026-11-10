# Security incident response

1. **Triage.** Record UTC discovery time, affected host/account, indicators and scope in a restricted incident record. Preserve relevant logs and a protected database snapshot before cleanup. Do not paste secrets or personal data into GitHub.
2. **Contain.** Revoke affected sessions and Sanctum tokens, disable compromised Telegram integration if needed, restrict network access, and stop compromised workers. Keep enough evidence to understand the event.
3. **Rotate secrets.** Rotate the affected credentials using the steps below. Coordinate changes to application environment, workers, scheduler, webhook registration and database grants. Verify old credentials fail after cutover.
4. **Recover.** Apply the fix, deploy through the normal review process, run health and authorization checks, inspect queue failures, and restore from a verified encrypted backup only if required. Reapply any deletion requests that occurred after the restored backup.
5. **Close.** Determine affected users and notification obligations with the owner/legal adviser, document timeline and corrective actions, and create follow-up issues without sensitive data.

## Rotation runbooks

**APP_KEY:** Create a new key on a protected host. Preserve the old key only in `APP_PREVIOUS_KEYS` for the shortest migration window needed to decrypt existing encrypted values; update all app and worker processes together, clear cached config, and test encrypted data and sessions. Then remove the old key after dependent values are re-encrypted or expired. Changing the key without a previous key can make encrypted data unreadable and invalidate sessions, so test a backup restore first.

**Telegram bot token and webhook secret:** Revoke/regenerate the bot token using Telegram's official bot management flow, set the new token in the deployment secret store, generate a new independent webhook secret and route key, then register `setWebhook` over HTTPS with the new URL and `secret_token`. Restart workers, verify a real private-chat message and callback, and confirm the old URL/header cannot authenticate. Treat any captured update payload as sensitive.

**MariaDB password:** Create/rotate the application user's password in MariaDB, update the deployment secret store, and restart app/worker/scheduler connections in a coordinated window. Verify least-privilege app access and that the old password fails. Rotate backup credentials separately if used.

**Backup encryption identity:** Generate a new recipient/key pair, update the backup job and protected restore location, run an encrypted backup and restore test, and retain the old private key only until old backups expire or are re-encrypted. Restrict key access and record custody.

After any rotation, inspect logs for failed authentication and unexpected data access. Production commands and notification decisions require the operator's authorization and actual host context.
