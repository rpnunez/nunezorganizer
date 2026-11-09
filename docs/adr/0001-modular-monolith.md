# ADR 0001: Modular monolith on Laravel + MariaDB

- Status: Accepted (2026-11-08)

## Context
NunezOrganizer is a small, multi-user accountability app. One developer runs it with coding agents, on a shared VPS (AlmaLinux 8.10, OpenLiteSpeed, MariaDB, PHP 8.4). It needs a web UI, an API, a scheduler, background jobs and a Telegram integration.

## Decision
Build **one Laravel 13 application** (PHP ^8.4) with domain modules under `app/Domain/*` and integrations under `app/Integrations/*`. It uses **one MariaDB schema** and the **database queue driver**, with Blade + Livewire + Flux for the UI.

## Consequences
- One deployable, one database, simple transactions across tasks, responses and notifications.
- Module boundaries are a convention, enforced in review: integrations call domain services and never write domain tables directly.
- Redis, extra services or a separate SPA are added only when there is measured need.
