# Changelog

## 2.1.0 — 2026-09-19

- Fresh installations can choose `flow.morph_key_type` as `int` (the default), `uuid`, or `ulid`. Existing installations are not converted automatically.
- Undefined actions now throw `InvalidArgumentException` without completing the current step. Terminal actions must explicitly transition to `END`.
- Unsupported assignment strategy names now throw instead of silently selecting `single_user`; the definition command rejects them before saving.
- Corrected requirements, provider/config publication instructions, assignment limitations, tenancy and replay responsibilities, and removed the publication entry for nonexistent stubs.
- Added SQLite relationship/transition checks for each morph key type, MySQL schema/transition coverage, and fresh Laravel consumer migration coverage for each type.

## 2.0.0 — 2026-09-12

Laravel 12/13; package configuration, migrations and notification view discovery now use actual paths. Removed the unnecessary package-tools dependency and moved testing tools to development requirements. Start/transition operations are transactional; duplicate completed-step transitions are rejected; events are dispatched after commit.
