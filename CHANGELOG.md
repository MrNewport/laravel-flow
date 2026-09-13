# Changelog

## 2.0.0 — 2026-09-12

Laravel 12/13; package configuration, migrations and notification view discovery now use actual paths. Removed the unnecessary package-tools dependency and moved testing tools to development requirements. Start/transition operations are transactional; duplicate completed-step transitions are rejected; events are dispatched after commit.
