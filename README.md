# Laravel Flow

Release **2.1.0**. Transactional workflow steps, transitions, assignment records, and action events for Laravel.

| Laravel | PHP |
| --- | --- |
| 12 | 8.2+ |
| 13 | 8.3+ |

The package depends on Illuminate support, database, notifications, and events. Testbench and Pest are development dependencies. See the GitHub Actions compatibility matrix for tested combinations.

## Installation

```sh
composer require mrnewport/laravel-flow:^2.1
```

If the release is not yet indexed on Packagist, configure the published repository as a Composer VCS repository:

```sh
composer config repositories.laravel-flow vcs https://github.com/MrNewport/laravel-flow
composer require mrnewport/laravel-flow:^2.1
```

Laravel discovers `MrNewport\LaravelFlow\Providers\FlowServiceProvider` automatically. Publish optional configuration with:

```sh
php artisan vendor:publish --provider='MrNewport\LaravelFlow\Providers\FlowServiceProvider' --tag=flow-config
```

### Related model key types

Before the **first** package migration, choose the primary-key type used by workflow subjects in `config/flow.php`:

```php
return [
    'morph_key_type' => 'int', // int (default), uuid, or ulid
    'user_model' => App\Models\User::class,
    'rejection_actions' => ['reject', 'cancel'],
];
```

Then run `php artisan migrate`. The setting determines `flow_instances.model_id`: unsigned bigint, UUID, or ULID. The package's own instance and step IDs remain unsigned bigints. Unsupported settings fail before any package tables are created.

**Changing configuration does not convert existing tables.** Existing installations require a separately reviewed application migration and data conversion plan. Back up production data before migrations; do not roll back production tables to change key type. One installation uses one configured subject-key type; mixed key types are not automatically converted.

`user_model` and `rejection_actions` are available to application integrations; the manager does not use them to enforce permissions or special cancellation behavior.

## Define a workflow

Steps have stable string IDs. Transitions identify allowed actions. Version step IDs when changing a workflow that has active instances.

```php
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Models\FlowTransition;

FlowStep::updateOrCreate(
    ['id' => 'proposal.review.v1'],
    ['name' => 'Review proposal'],
);

foreach (['approve', 'reject'] as $action) {
    FlowTransition::firstOrCreate([
        'step_id' => 'proposal.review.v1',
        'action' => $action,
        'next_step_id' => 'END',
    ]);
}
```

The migration creates the reserved `END` definition. A transition to `END` finishes that branch without creating another instance step.

The definition command is an alternative:

```sh
php artisan flow:define-step proposal.review.v1 "Review proposal" \
  --actions="approve:END,reject:END"
```

The command upserts supplied transitions; it does not delete old ones. Omitted definition options reset to their defaults. Validate application-supplied definitions before saving them.

## Start and advance a workflow

Use a persisted Eloquent subject whose primary key matches the configured type:

```php
use MrNewport\LaravelFlow\FlowManager;

// Run within your application's authorized transaction.
$instance = FlowManager::startFlow('proposal.review.v1', $proposal);
$step = $instance->steps()->whereNull('finished_at')->sole();
$nextSteps = FlowManager::actionStep($step, 'approve');
```

`startFlow(string $stepId, $entity): FlowInstance` creates an instance and its initial step. It does not deduplicate starts; the application must enforce its intended one-instance or multiple-instance rule.

`actionStep(FlowInstanceStep $step, string $action): array` locks and completes the persisted step and creates its successors in one transaction. It returns created steps; an `END` transition contributes null.

- Undefined actions throw `InvalidArgumentException` and leave the step unchanged.
- Repeating a completed step throws `LogicException`; this is not an idempotent success response.
- Missing successor definitions or failed assignments roll back the transition.
- Multiple matching transitions create multiple successors. `current_step_id` records the last successor, not aggregate parallel progress. Inspect unfinished instance steps explicitly.
- Branch joins, quorum approval, and “wait for all branches” completion are not implemented.

### Model convenience methods

```php
use Illuminate\Database\Eloquent\Model;
use MrNewport\LaravelFlow\Traits\Flowable;

class Proposal extends Model
{
    use Flowable;
}

$proposal->startFlow('proposal.review.v1');
$step = $proposal->currentFlowStep();
$proposal->flowAction('approve');
```

The trait exposes a single `flowInstance()` relation. `currentFlowStep()` chooses the first unfinished step; use explicit instances and steps for parallel workflows or multiple workflows per subject. `flowAction()` does nothing when no unfinished step exists.

## Assignments

| Strategy | Parameters | Records |
| --- | --- | --- |
| `single_user` | `['user_id' => 7]` | One user assignment |
| `multi_user` | `['user_ids' => [7, 8]]` | One assignment per user |
| `email_list` | `['emails' => ['reviewer@example.com']]` | One assignment per email |

Set `assignment_strategy` and `assignment_params` on a step definition. A null strategy creates no assignments. Unknown strategy names throw `InvalidArgumentException`; they no longer silently select `single_user`.

There is no custom-strategy registry. Implementing `AssignmentStrategyInterface` alone does not register a strategy with `FlowManager`. Applications can manage assignment rows through their own authorized service when the built-ins do not fit.

```php
$changed = FlowManager::reassignStep($step, $currentUser, [9, 10]);
```

The user strategies allow an existing assigned user to reassign; single-user keeps only the first supplied identity. The email strategy does not permit manager-based reassignment. Validate new identities, tenant membership, and application reassignment policies. Reassignment does not acquire a transaction or row lock; wrap it in an application transaction and lock relevant records when concurrent updates are possible.

Assignments do not authorize `actionStep()`, enforce a quorum, or prove workspace membership.

## Events and notifications

Each successful transition dispatches `MrNewport\LaravelFlow\Events\FlowActionEvent` **after the surrounding database transaction commits**. It carries `oldStep` (the completed step), `action`, and `newStep` (the successor, or null for `END`). Rollback prevents action events from dispatching.

Applications choose listeners and side effects. Events do not include actor or tenant context automatically; retain that context in application records.

The step's `notify` flag is metadata. Nothing is emailed automatically. Applications select authorized recipients and send `MrNewport\LaravelFlow\Notifications\FlowBaseNotification($step, $action)` or their own notification. The supplied mail view is `laravel-flow::notifications.flow_base`.

## Application boundaries

Flow is an orchestration primitive. Before starting or advancing a workflow, the application must:

- Resolve the subject within the current tenant/workspace and authorize the actor.
- Resolve its related instance and expected step; do not trust arbitrary request-supplied step IDs.
- Validate the action, expected domain version, assignment policy, and business conditions.
- Keep domain mutation, workflow transition, and audit records in one transaction on the same database connection.
- Preserve request-key deduplication, immutable reviewed snapshots, worker leases/fencing, and replay handling where required.

Package tables have no workspace ID or tenant scope. Instances are not automatically unique per subject, and step history is not an immutable application audit trail. The manager stores subject class names; applications using morph aliases should review their relation mapping.

A workflow can coordinate reviewed imports or approvals while the application continues to own exact selected rows, authorization fingerprints, revision versions, and idempotent worker execution.

## Upgrade from 2.0

No existing tables are altered. Integer subjects keep the default. Two formerly permissive behaviors now fail explicitly:

1. Actions without configured transitions throw instead of closing the step. Define an explicit `END` transition for terminal actions.
2. Unsupported strategy names throw instead of silently selecting `single_user`. Use a built-in strategy or application-managed assignments.

The provider namespace includes `Providers`: `MrNewport\LaravelFlow\Providers\FlowServiceProvider`. There are no bundled stubs to publish.

## Tests

Run `composer test` in the package checkout. Tests cover transactions, invalid and repeated actions, assignments, events, notifications, commands, and integer/UUID/ULID subject relationships. CI also runs isolated MySQL schema/transition/rollback tests and fresh Laravel consumers with production dependencies only for all three key types.

## License

MIT.
