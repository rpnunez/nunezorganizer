# Security policy

NunezOrganizer handles personal schedules and Telegram account links. Please report vulnerabilities **privately**.

## Reporting
Use GitHub's **private vulnerability reporting**: *Security* tab → *Report a vulnerability*. Please don't open public issues for security problems.

Include affected endpoints or flows, reproduction steps, and the impact (for example, cross-tenant data access or webhook spoofing). You'll get an acknowledgement as soon as possible.

## Scope highlights
- Cross-tenant data access (one user reading or changing another user's tasks)
- Telegram webhook or callback spoofing, replay, or pairing hijack
- Authentication, invitation or token bypass
- Secret leakage (logs, errors, repository)

## Supported versions
Only the latest `master` is supported during pre-1.0 development.
