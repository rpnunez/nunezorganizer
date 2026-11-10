# Changelog

## Unreleased — proposed v0.1.0

First private NunezOrganizer web MVP pilot. The release is pending host verification and the two-user acceptance run; no `v0.1.0` tag has been created.

### Included

- Invite-only accounts with tenant isolation, email verification and account settings.
- Task scheduling, Yes/No completion, unanswered carryover, backlog and mobile web views.
- Private Telegram pairing, end-of-block prompts, callback replay protection and delivery recovery.
- Local-time daily reviews, weekly progress and optional routine progression suggestions.
- Operator deployment, encrypted backup and restore tooling, plus security and user-data controls.

### Operational notes

- Target host: `organizer.nunezserver.online`; no staging vhost is currently available.
- Complete the [v0.1 pilot checklist](docs/release/v0.1-checklist.md) before go/no-go. Deployment and real Telegram testing require owner authorization.
- For code rollback, use [the deployment runbook](docs/runbook.md#rollback). Review migration compatibility before switching releases; never run a production data restore as a routine code rollback.
- The release does not include Google Calendar, ChatGPT integration, or alternative notification channels (M7).
