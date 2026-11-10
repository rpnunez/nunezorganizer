# Prevent Duplicate Telegram Prompts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop automatic resends when Telegram may already have accepted a completion prompt or daily review.

**Architecture:** Keep MariaDB as the delivery ledger. Once a provider call begins, an unexpected exception or stale `sending` record becomes `uncertain`, never automatically `pending`; explicit Telegram 429 remains retryable. Expose uncertain counts and IDs through the existing operator health command and document the tradeoff.

**Tech Stack:** Laravel 13, PHP 8.4, MariaDB 10.11, database queue, PHPUnit.

**Spec:** GitHub issue #46, https://github.com/rpnunez/nunezorganizer/issues/46.

## Global Constraints

- Do not change task completion, callback idempotency, or tenant authorization.
- Do not expose Telegram credentials or message content in logs or health output.
- Do not introduce external infrastructure or new routes.

## Review Focus

- Worker stops after Telegram accepts a message: recovery must not resend.
- Telegram 429: delivery remains pending for retry.
- Provider timeout after send begins: delivery becomes uncertain and is not retried.
- Daily review uses the same policy and does not falsely report `sent`.
- Duplicate recovery command runs leave an uncertain delivery unchanged.

---

### Task 1: Pin delivery outcomes with tests

**Files:** `tests/Feature/Notifications/DispatchDueTest.php`, `tests/Feature/Telegram/DeliveryFailureTest.php`, `tests/Feature/Reviews/DispatchReviewsTest.php`

- [x] Add tests for a stale `sending` prompt and review, including repeated recovery and no requeued job.
- [x] Add fake-channel tests for an exception after an accepted send; assert `uncertain` and no second send.
- [x] Keep existing 429 and permanent-failure tests as regression checks.
- [ ] Run focused tests to observe the expected failures before implementation.

### Task 2: Make uncertain delivery terminal for automatic processing

**Files:** `app/Domain/Notifications/Jobs/SendCompletionPrompt.php`, `app/Domain/Notifications/Jobs/SendDailyReview.php`, `app/Console/Commands/RecoverNotificationDeliveries.php`

- [x] Change unexpected provider-call exceptions to record `status=uncertain`, `last_error_code=uncertain_send`, and rethrow; the health command remains the durable operator signal.
- [x] Change stale `sending` recovery to mark `uncertain` if no external ID is saved; keep the saved-ID case as `sent`.
- [x] Never dispatch a new job for an uncertain delivery; preserve explicit 429 retry.
- [ ] Run focused tests and check status transitions.

### Task 3: Operator visibility and documentation

**Files:** `app/Console/Commands/NotificationHealth.php`, `tests/Feature/Notifications/DispatchDueTest.php`, `docs/architecture.md`

- [x] Report uncertain delivery count and IDs in `nunez:health` without user data or message content.
- [x] Document the no-automatic-resend tradeoff, operator review, and manual intervention boundaries.
- [ ] Run the full required repository gates where the local environment permits; report exact blockers.

## Verification note

The host PHP is 8.2.12; Composer dependencies require 8.4.1 or newer. The Docker `app` service is stopped. Focused PHPUnit, Pint, and PHPStan were attempted but could not start. `php -l` passed on all changed PHP files, and `git diff --check` passed. Runtime checkboxes remain open until PHP 8.4/MariaDB is available.
