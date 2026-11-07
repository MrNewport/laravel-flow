# Changelog

## 2.2.0 — 2026-11-06

- Mandatory model writes reject observer vetoes and preserve transaction atomicity across steps, assignments and definition commands.
- Transition instance mutations use the saved unique tuple. Subject reassignment validates persisted bindings, and opaque user assignment keys compare exactly.
- Native `END` routing rejects database collation aliases. Integer subject keys respect the selected driver's exact storage range.
- MySQL history dates widen through a forward migration without narrowing saved history on rollback.
- Configurable fanout, assignment and atomic conversion budgets bound mutation work. Conversion preview exposes maintenance diagnostics and scans bounded result batches.
- Literal-text notification content, real rendered mail tests and publishable language resources support accessible host integrations.
- Formatting, Eloquent-aware static analysis, minimum-dependency compatibility and adversarial regression checks are included in development verification.

- Eloquent subjects use `getMorphClass()` with registered or enforced morph maps. Generic `getKey()` objects use their class-name representation.
- `Flowable::flowInstance()` accepts the current alias and historical FQCN rows through a `HasOne` relation.
- `flow:morph-map` and `MorphMapMigration` provide fingerprint-checked, transactional conversion of saved subject types. Conversion preserves instance IDs, subject keys, timestamps, steps and assignments.
- Definition and transition queries use locking/current reads under MySQL REPEATABLE READ. Fan-out actions fetch successor definitions in batches and update the instance pointer once.
- Reassignment uses persisted pending-state and assignment locks in one transaction. Assignment observers retain transactional behavior.
- Subjects and keys are validated against the configured connection and key type. Model convenience methods resolve fresh pending state and bind morph aliases as strings.
- Step-definition commands validate inputs before persisting the step and transitions transactionally.
- An additive migration indexes subject lookups and ordered pending-step queries without changing saved records.
- Tests cover enforced morph maps, saved-data conversion for integer/UUID/ULID keys, conversion rollback and two-connection MySQL definition visibility.

## 2.1.0 — 2026-09-19

- Fresh installations support `int`, `uuid` and `ulid` through `flow.morph_key_type`. Existing tables retain their column types.
- Undefined actions raise `InvalidArgumentException` without completing the step. Terminal actions use explicit `END` transitions.
- Unsupported assignment strategies raise `InvalidArgumentException`; the definition command validates strategies before saving.
- SQLite and MySQL coverage includes subject relationships, transitions and schema operations for each key type.

## 2.0.0 — 2026-09-12

- Laravel 12/13 support with package configuration, migrations and notification views.
- Transactional starts and transitions, completed-step protection and after-commit transition events.
- Testbench and Pest are development dependencies.
