# ADR 0005: MariaDB is the authoritative test database

- Status: Accepted (2026-11-08)

## Context
Production runs MariaDB. The domain relies on `SELECT … FOR UPDATE` row locks, unique constraints for idempotency, and transactional semantics. SQLite treats `lockForUpdate()` as a no-op and differs in constraint and JSON behavior.

## Decision
The test suite runs against **MariaDB 10.11**: Docker locally and a service container in CI. `phpunit.xml` forces `DB_DATABASE=nunezorganizer_test` so tests can never touch the development database. SQLite is not supported for tests.

## Consequences
Tests are slightly slower and need a running MariaDB. In exchange, concurrency and idempotency tests prove real behavior.
