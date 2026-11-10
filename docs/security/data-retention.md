# User data controls and retention

Only an authorized operator with application CLI access may export or delete a user. Verify the requester's identity and account email out of band, record the request in the operator's secure ticket system, and avoid putting export data in tickets, logs or shell history.

## Export

Run `php artisan nunez:user:export user@example.com --output=/private/path/export.json` on the application host. The destination must be a new file in a restricted directory; the command creates it exclusively with owner-only permissions. It includes the user's profile, preferences, owned tasks, responses, routine, Telegram link/delivery records and audit events where that user was the actor. Password hashes, API tokens, pairing secrets and callback token hashes are excluded. Transfer it through an approved secure channel, then remove the local export after delivery.

## Delete

After confirming the request and any required retention hold, run `php artisan nunez:user:delete user@example.com --confirm-email=user@example.com`. The command refuses a shared account; resolve that case with a reviewed, account-specific process. For a personal account, it removes user-owned records and tokens in one transaction and retains only audit action and timestamp, clearing actor, tenant, subject and metadata. Verify the account is absent and record completion without storing its data. Do not restore an old backup over the live database after deletion without replaying the deletion.

## Retention boundaries

Live personal data remains until an operator deletes the account or a future product retention rule is implemented. Password reset tokens and sessions follow the framework's configured expiry and cleanup; Telegram update IDs and deliveries require scheduled pruning rules before a production retention promise can be made. Backups can contain deleted data until their configured retention window expires; issue #21's proposed encrypted backup job uses 14 days, subject to actual deployment verification. Legal holds and jurisdiction-specific retention requirements require an owner decision before deletion. Do not promise immediate erasure from offline backups.
