# Laravel Flow handbook

## Fresh installation

Flow is a native Eloquent transition/assignment engine. It has no UI dependency and works without Laravel Workflow or Laravel Fields.

```sh
composer create-project laravel/laravel review-app '^12.0'
cd review-app
composer require mrnewport/laravel-flow:^2.2
php artisan vendor:publish --tag=flow-config
```

Use `'^13.0'` for Laravel 13 with its supported PHP version. Flow's own PHP floor is 8.2; Laravel's runtime requirement still applies. If a registry has not indexed the release, configure `composer config repositories.laravel-flow vcs https://github.com/MrNewport/laravel-flow` and repeat the requirement. Laravel auto-discovers `MrNewport\LaravelFlow\Providers\FlowServiceProvider`; register it in `bootstrap/providers.php` if discovery is disabled.

Before migrating choose the subject key type in `config/flow.php`, then run `php artisan migrate`. The provider loads migrations, views, translations and both Artisan commands. No routes are registered. Native Flow has no assets to compile.

### Configuration

| Key | Default | Contract |
| --- | --- | --- |
| `morph_key_type` | `int` | `int`, `uuid` or `ulid`; selects the initial `flow_instances.model_id` column and runtime subject validation. |
| `max_transitions_per_action` | 1000 | Admission budget before one action mutates anything. |
| `max_assignments` | 250 | Maximum new/replacement identities in a built-in assignment operation. |
| `max_morph_conversion_rows` | 100000 | Maximum total rows in a reviewed atomic morph conversion. |
| `user_model` | `App\\Models\\User` | Integration configuration; manager does not query it for authorization. |
| `rejection_actions` | `['reject', 'cancel']` | Integration configuration; no automatic cancellation/rejection semantics. Define transitions explicitly. |

All three budgets must be actual PHP integers from 1 through 1,000,000; numeric strings, booleans and invalid ranges fail with `InvalidArgumentException`. Raising a limit changes transaction, memory, observer and downstream delivery costs. Choose from measured worker/database capacity.

## Define, start and complete a flow

A definition consists of stable string step IDs and transition rows. Seed reviewed definitions in a migration/seeder or use the definition command. For a two-stage review:

```php
use MrNewport\LaravelFlow\Models\{FlowStep, FlowTransition};

FlowStep::updateOrCreate(['id' => 'purchase.draft.v1'], ['name' => 'Prepare purchase']);
FlowStep::updateOrCreate(['id' => 'purchase.review.v1'], [
    'name' => 'Review purchase',
    'assignment_strategy' => 'single_user',
    'assignment_params' => ['user_id' => 17],
]);
foreach ([
    ['purchase.draft.v1', 'submit', 'purchase.review.v1'],
    ['purchase.review.v1', 'approve', 'END'],
    ['purchase.review.v1', 'revise', 'purchase.draft.v1'],
    ['purchase.review.v1', 'cancel', 'END'],
] as [$from, $action, $to]) {
    FlowTransition::firstOrCreate(['step_id' => $from, 'action' => $action, 'next_step_id' => $to]);
}
```

IDs/actions/targets in manager/command input must be nonempty text up to 100 bytes without control characters. Use stable lowercase portable IDs for consistent lookup across database collations. The migration inserts reserved exact `END`. It is a completion target, cannot be the initial step, and does not create a pending instance-step row. Case aliases that resolve to END are refused. Names default to `ucfirst($id)` when empty. Existing definitions are mutable records: version IDs yourself before changing graphs with active instances, or use the optional Workflow publisher for immutable snapshots.

```php
use MrNewport\LaravelFlow\FlowManager;

$instance = FlowManager::startFlow('purchase.draft.v1', $purchase);
$draft = $instance->steps()->whereNull('finished_at')->sole();
$created = FlowManager::actionStep($draft, 'submit');
$review = $created[0];
$completed = FlowManager::actionStep($review, 'approve'); // [null]
```

`startFlow(string $stepId, $entity): FlowInstance` validates the subject, locks/loads the definition, creates one instance and initial pending step, then assigns it if configured. Unknown definitions produce `ModelNotFoundException`. Invalid subjects/strategies/limits/identities fail before the enclosing transaction commits. No start action event is emitted.

`actionStep(FlowInstanceStep $currentStep, string $action): array` reloads the step under a write lock and refuses an already completed step with `LogicException`. It finds matching transitions, enforces the budget, resolves every target, locks the instance, completes the old step and creates/assigns successors. Each returned entry is a new FlowInstanceStep or null for an END transition. The caller's original object may still be stale: call `refresh()` or query again. An undefined action raises `InvalidArgumentException` without completing the step. Missing targets raise `ModelNotFoundException` and roll the whole action back.

### Subject identity

Eloquent subjects must already be saved and share the Flow database **connection name**. Keys must match configuration. Generic objects may expose `getKey()` and are stored by their class name; the host must supply appropriate relationship recovery for them. They are not automatically Eloquent morph targets.

| Key type | Accepted identity | Database contract |
| --- | --- | --- |
| `int` | Canonical nonnegative integer/int string, no leading zeros except `0`. | SQLite ≤9223372036854775807; MySQL unsigned ≤18446744073709551615. |
| `uuid` | Standard 36-character hex UUID string. | UUID column; same key type for every subject in this installation. |
| `ulid` | Valid 26-character ULID text, allowed first-character range. | ULID column; package's own record IDs remain bigint. |

Unsaved Eloquent objects, empty/noninteger/nonstring keys, mismatched types and cross-connection subjects raise `InvalidArgumentException` before creating records. All subject models in one installation need the configured column-compatible type. Changing config does not convert existing schema/data.

## Models and relationships

### FlowStep

Table `flow_steps`; nonincrementing string `id`, `name`, `notify`, `assignment_strategy`, JSON-cast `assignment_params`, timestamps. `transitions(): HasMany` returns transitions whose step_id matches. A blank name on creation/update falls back to ucfirst(id). `notify` is stored metadata; FlowManager does **not** send automatic mail when it is true. Add an application listener after authorization/recipient policy is defined.

### FlowTransition

Table `flow_transitions`; `(step_id, action, next_step_id)` is a unique tuple; no surrogate ID or timestamps. `step(): BelongsTo` and `nextStep(): BelongsTo` resolve source/target definitions. Instance `save()`, `delete()` and refresh/select operations use the original tuple as identity, so an edited tuple updates only its original row. Query transitions by all needed columns; do not assume `find($id)` exists for this composite identity. Duplicate tuples fail the database unique constraint. Eloquent bulk update/delete follows normal database behavior and bypasses per-model observers.

### FlowInstance

Table `flow_instances`; bigint `id`, convenience `current_step_id`, subject `model_type`/`model_id`, timestamps. `entity(): MorphTo` resolves the Eloquent subject. `steps(): HasMany` returns instance step history. `currentStep(): BelongsTo` resolves the definition referenced by the convenience pointer; it is **not** a pending instance-step query. A terminal branch may set that pointer to END while other branches remain active.

### FlowInstanceStep

Table `flow_instance_steps`; bigint `id`, `flow_instance_id`, string `step_id`, nullable `action_taken`, `started_at`, nullable `finished_at`, soft-delete `deleted_at`, timestamps. Dates are Carbon-cast. Relations: `flowInstance(): BelongsTo`, `step(): BelongsTo`, `assignees(): HasMany`.

Pending work is `whereNull('finished_at')` with default soft-delete scope. For historical audit explicitly choose whether `withTrashed()` is appropriate. Soft-deleting a pending row removes it from ordinary pending queries; it does not perform an action, clean up a domain record or emit an action event. `completeStep(string $action)` is an internal low-level write (no declared return); applications should call the manager because the low-level method does not validate transitions, lock pending state or create successors.

### FlowStepAssignee

Table `flow_step_assignees`; bigint `id`, `flow_instance_step_id`, nullable string `assignee_type` and `assignee_value`, timestamps. `instanceStep()` returns its BelongsTo relation. Built-ins store type `user` or `email` and string identities. The package does not impose user-table foreign keys, tenancy or recipient lookup. Keep identities stable and map them explicitly in the host.

### Useful queries

```php
$pending = $instance->steps()->whereNull('finished_at')->orderBy('id')->get();
$isComplete = ! $instance->steps()->whereNull('finished_at')->exists();
$history = $instance->steps()->with(['step', 'assignees'])->orderBy('id')->get();
$allowed = $step->step()->firstOrFail()->transitions()->pluck('action')->unique();
$instances = $purchase->flowInstance()->get();
```

These are data queries, not authorization. Apply an authorized subject/run scope before loading globally addressable steps. A native instance has no tenant or actor column. Audit attribution and retention belong to the host or Workflow. Indexes support `(model_type, model_id)` and `(flow_instance_id, finished_at, deleted_at, id)`.

## Flowable trait

Add `MrNewport\LaravelFlow\Traits\Flowable` to an Eloquent model:

```php
class Purchase extends \Illuminate\Database\Eloquent\Model
{
    use \MrNewport\LaravelFlow\Traits\Flowable;
}
```

| Method | Return / behavior |
| --- | --- |
| `flowInstance(): HasOne` | Subject-key relation accepting the current morph alias and historical FQCN. Native storage permits multiple instances, but this convenience relation chooses one. |
| `startFlow(string $stepId)` | Returns FlowInstance and invalidates a cached flowInstance relation. Does not deduplicate repeated starts. |
| `currentFlowStep(): ?FlowInstanceStep` | Queries persisted state and returns first pending step ordered by ID, or null. |
| `flowAction(string $action)` | Acts on that first pending step; no value returned, and no-op if none. Use manager for all result entries. |
| `flowReassign(FlowInstanceStep, $currentUser, array $newAssignees)` | Returns manager bool and verifies this step belongs to this subject. |

Use explicit instances and pending-step IDs for multiple runs or parallel branches. The trait is a convenience for simple one-flow subjects, not an aggregate completion or join API.

## Assignment strategies and exact identities

`AssignmentStrategyFactory::make(?string $strategy, ?array $params = []): AssignmentStrategyInterface` recognizes `single_user`, `multi_user`, `email_list`. Null directly constructs SingleUserStrategy, but the manager skips assignment entirely when the stored step strategy is empty. An unknown name raises `InvalidArgumentException`; it is never silently treated as single-user.

| Strategy | Initial parameters | Reassignment behavior |
| --- | --- | --- |
| `single_user` | `['user_id' => 17]`; absent/null ID gives no assignment. | Current assigned user may reassign; validates whole proposed list, retains its first identity. Empty list clears. |
| `multi_user` | `['user_ids' => [17, 23]]`; missing list gives empty. | Current assigned user may replace the whole list. Empty list clears. |
| `email_list` | `['emails' => ['review@example.test']]`; missing list gives empty. | `canReassign()` always false; manager returns false. Direct strategy reassign is available only through host-authorized composition. |

Lists must be actual lists, within `flow.max_assignments`, containing distinct int/string identities. IDs become strings; `[17, '17']` is a duplicate. Text is nonblank, ≤255 bytes and contains no control characters; email addresses also pass `FILTER_VALIDATE_EMAIL`. User identities are opaque and byte-sensitive: case-distinct IDs do not confer reassignment rights even on case-insensitive database collations. The user object is resolved through `getKey()` or fallback public id, not an assumption that the database key is named id. Built-ins do not prove the user exists or belongs to the subject's workspace; the host must check every destination.

`reassignStep(FlowInstanceStep $step, $currentUser, array $newAssignees, $subject = null): bool` locks/reloads persisted pending state, optionally verifies subject ownership, loads strategy, calls `canReassign()` and replaces assignments. Returns false for no strategy or denied reassignment. Completed steps raise `LogicException`. Invalid lists/ownership raise `InvalidArgumentException`. Replacement happens atomically: a failing assignment write restores the prior complete set. It emits no FlowActionEvent.

### Custom strategies and factory composition

The public strategy interface is:

```php
interface AssignmentStrategyInterface
{
    public function assign(FlowInstanceStep $instanceStep): void;
    public function canReassign(FlowInstanceStep $instanceStep, $currentUser): bool;
    public function reassign(FlowInstanceStep $instanceStep, array $newAssignees): void;
}
```

The manager calls the static built-in factory directly. Rebinding that class in Laravel's container does not change its behavior, and arbitrary strategy names cannot be stored expecting runtime discovery. For domain-specific selection, leave `assignment_strategy` null, resolve recipients in a host service/factory and call a tested built-in strategy (or your interface implementation) inside the same outer transaction as start/action. For each returned successor needing domain routing, invoke that strategy before the outer transaction commits. Reuse `AssignmentValues` and `AssignmentMutation` for bounded, pending-state-locked writes; put domain authorization/destination policy in the host. See the [custom routing recipe](RECIPES.md#custom-recipient-routing-with-the-existing-strategy-contract).

`AssignmentValues::users()`/`emails()` normalize new lists; `usersForStored()`/`emailsForStored()` validate saved identity lists without imposing a newly lowered admission count, retaining shape/byte/duplicate checks. Those stored-data helpers are not a way to admit new oversized writes. `AssignmentMutation::write($step, $type, $identities, $replace)` writes a prevalidated list atomically and `containsUser()` performs exact user membership. Use these lower-level helpers only from trusted adapters that enforce their input contract.

## Transactions, concurrency and parallel branches

Start, action and reassignment each run on Flow's database connection. Compose domain changes in an outer transaction on that same connection. Definitions are read with shared locks; action/reassignment reload the pending step with a write lock. Action also locks the instance so concurrent branches serialize their shared pointer update. MySQL locking reads observe committed definitions even when an outer transaction established an earlier snapshot.

If any target, assignment, observer or mandatory save fails, the operation rolls back. A model observer returning false for a required create/save becomes `RuntimeException` rather than apparent success. Assignment insert observers are preserved. Eloquent bulk deletions used to replace old assignments do not invoke per-row delete observers; implement audit policy accordingly.

Native Flow does not automatically retry an operation, deduplicate starts, record the authenticated actor, implement request receipts or decide an action's domain permissions. A duplicate action on the same persisted step fails as already completed. An uncertain response after commit must be reconciled against saved state; do not blindly create a new flow. If automatic transaction retry is added in the host, callbacks must tolerate reruns and exclude external side effects.

Multiple transition rows for one action create independent successor steps. All matching transitions run; this is fan-out, not a join, quorum or exclusive gateway. Results follow database retrieval order, which is not a stable semantic ordering; `current_step_id` holds the last target processed. To know whether work remains query **all** nondeleted pending steps. If two branches both target a review step, two distinct instance-step rows are created, not a merged/joined step. Native data also permits an END transition alongside other successors; a terminal result does not imply global completion. Enforce a stricter graph rule in the host or Workflow when required.

Successor definitions are batched in chunks of 500. The transition budget is checked with at most limit+1 records before writing; assignments retain individual model writes. Test actual fan-out, locks, observer behavior and event cost before raising budgets.

## Events, notifications and delivery recovery

One `FlowActionEvent($oldStep, $action, ?$newStep)` is dispatched for each transition, after the **outermost** transaction commits. Its public properties are `oldStep`, `action`, `newStep`; terminal transitions have null newStep. Start, reassignment, rollback and direct model edits do not automatically produce this action event. The event implements `ShouldDispatchAfterCommit` and serializes models for queued listeners.

Register host listeners in normal Laravel configuration:

```php
use Illuminate\Support\Facades\Event;
use MrNewport\LaravelFlow\Events\FlowActionEvent;

Event::listen(FlowActionEvent::class, function (FlowActionEvent $event): void {
    // Resolve only authorized recipients; schedule host-owned delivery work.
});
```

`FlowBaseNotification(FlowInstanceStep $step, string $action)` is an optional mail notification. `via($notifiable)` returns `['mail']`; `toMail()` uses translated subject text and `laravel-flow::notifications.flow_base` Markdown. It does not implement ShouldQueue itself. Subjects strip CR/LF, view text is escaped, and the default button points to `config('app.url')`. Extend it to provide an authorized application step URL and recipient/content policy, or use your own notification. Neither notify=true nor assignment creation sends it automatically.

After-commit dispatch is not durable delivery. A process may stop after committing and before dispatch; a listener may fail after the transition is durable. Do not re-run the transition to resend mail. For recoverable delivery, write a host outbox record with a unique logical event/step key inside the same outer transaction, let a worker track attempts, and retry delivery idempotently. Reconcile missing delivery records from persisted domain/step evidence if appropriate. Workflow command receipts can supply durable command IDs when the optional layer is used. Native Flow has no notification attempt table or exactly-once transport.

## Commands

### Define a step

```sh
php artisan flow:define-step purchase.review.v1 'Review purchase' \
  --notify=true --strategy=multi_user --params='{"user_ids":[17,23]}' \
  --actions='approve:END,revise:purchase.draft.v1,cancel:END'
```

Signature: `flow:define-step {stepId} {name?} {--notify=} {--strategy=} {--params=} {--actions=}`. The command validates identifiers, plain-text name (≤255 characters), boolean notify, JSON params, strategy identities and comma-separated action:target pairs before mutation. Invalid input prints an escaped error and returns FAILURE. JSON objects/arrays or null are accepted as params; built-ins validate their expected keys. Large JSON integers remain strings. Strategy params default to null; omitted notify defaults false.

It creates/updates the step and first-or-creates specified transition tuples in one transaction. **It does not delete old transitions**, validate the whole graph, require targets to exist immediately, version active definitions, or implement atomic graph publication. An omitted option can replace stored step attributes with defaults. Review command invocations as configuration changes. Runtime target validation still happens during actions. Successful output is escaped plain text.

### Preview/convert morph types

`flow:morph-map {--apply} {--expect=}` is described in the upgrade procedure below. No apply flag means preview only. Apply requires a full reviewed lowercase SHA-256. Unresolved types produce command failure; errors print without terminal formatting injection. JSON result output is raw JSON, suitable for review artifacts.

## Saved data, migrations and morph maps

The initial migration creates five tables and END. Fresh integer/UUID/ULID installations choose their subject column before running it. Existing installations running `php artisan migrate` receive additive subject/pending lookup indexes and a MySQL history-date widening migration. Existing instance/step/assignment IDs and values remain. The index rollback drops only those indexes. The MySQL date migration widens started_at/finished_at from TIMESTAMP to DATETIME while retaining clock values; its rollback intentionally keeps DATETIME to avoid truncating accepted dates outside 1970–2038. Store/hydrate dates using a consistent application/database timezone convention; do not assume package history is automatically UTC.

Do not roll back the initial migration to change key type: that drops workflow data. A populated int→UUID/ULID change requires a separately reviewed host mapping/schema migration, referential checks and restoration plan. Tables do not define automatic cascades for all domain relationships; plan retention/deletion explicitly.

### Reviewed morph-alias conversion

New Eloquent workflows store `getMorphClass()`: a registered alias or the FQCN when no map exists. Flowable reads both the current alias and historical FQCN. Register a non-enforced map first, for example `Relation::morphMap(['purchase' => Purchase::class])`. Eloquent inverse resolution also needs the registered map. Keep aliases stable for saved rows.

Before enabling `Relation::enforceMorphMap()` with historical FQCN rows:

1. Back up and test restoration on a copied database. Deploy the upgraded package to every writer and pause older writers/workflow writes during conversion.
2. Register exactly one intended alias per Eloquent class **without enforcement**. Prefer lowercase portable ASCII identifiers: letter followed by letters/digits/dot/underscore/hyphen. Conversion refuses case-only duplicates, duplicate class aliases, alias/class collisions, Unicode aliases and unknown types rather than guessing database collation equivalence.
3. Run `php artisan flow:morph-map`. Review types, destination aliases, counts, unresolved rows, fingerprint and elapsed/peak-memory/atomic-budget diagnostics.
4. Resolve every unknown/generic subject through an explicit reviewed host migration. If the total exceeds the budget, measure locks/memory/duration before explicitly changing `max_morph_conversion_rows`.
5. Apply the exact reviewed plan: `php artisan flow:morph-map --apply --expect=FULL_REVIEWED_SHA256`.
6. Preview again; verify no unresolved/FQCN conversions remain. Test both subject→instance and instance→entity relationships, step history and ownership. Then enforce the map and resume only upgraded writers.

The helper scans in batches of 1,000. Apply locks all reviewed rows and rechecks mapping plus ordered instance IDs/types/subject keys; a changed fingerprint fails before updates. Only model_type changes. IDs, keys, timestamps, steps and assignments are retained. The conversion is fully atomic, never silently partially committed. Row locks do not fence an old process inserting new FQCN rows, hence pausing old writers is required. A fresh zero-conversion preview can be replayed as a no-op; the stale preconversion fingerprint intentionally fails. No startup conversion, notifications or schema type conversion happens.

Programmatic API: `(new MorphMapMigration)->preview(): array` and `apply(string $expectedFingerprint): array`. Preview returns fingerprint/total/conversions/unresolved/diagnostics. Apply returns converted count and reviewed fingerprint. Invalid plan/mapping yields InvalidArgumentException or LogicException; any failed apply rolls back. Renaming aliases later needs another reviewed migration.

## Localization and verification

Publish `flow-translations` to override `lang/vendor/laravel-flow/<locale>/messages.php`; package exception/command/mail text uses `laravel-flow::messages`. Application definitions' names and UI labels remain application-owned. Override notification views under `resources/views/vendor/laravel-flow/notifications` when needed; there is no dedicated view publishing tag.

Run `composer check` for formatting, static analysis and tests; `composer test` runs Pest. The suite exercises integer/UUID/ULID subjects, morph-map conversion, observer vetoes, transaction rollback, stale/repeated actions, exact identities, fan-out budgets, definitions, notification escaping and MySQL current-read/collation/history behavior. [DocumentationExamplesTest](../tests/Feature/DocumentationExamplesTest.php) executes the handbook definition and parallel lifecycle. Before an application upgrade test copied saved records, policy/ownership, multiple pending branches, concurrent actions, rollback and delivery recovery. Package tests cannot prove application authorization or a production delivery provider.

## Optional package composition

[Laravel Workflow](https://github.com/MrNewport/laravel-workflow) adds scoped runs, immutable versioned definitions, policy contracts, replay receipts, optimistic versions, messages and reviewed history import. It calls native Flow for transitions/assignments. [Laravel Fields](https://github.com/MrNewport/laravel-fields) adds trusted immutable field definitions and normalization; its Workflow bridge validates action payloads. Filament is optional UI on Fields, and DocSign is optional document/PDF infrastructure. None is necessary for native Flow. See the [composition recipe](RECIPES.md#fields-and-workflow-composition) for choosing and connecting those responsibilities without claiming an installed dependency is an exercised integration.
