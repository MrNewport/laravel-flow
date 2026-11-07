# Native Flow recipes

These are application services built around the native engine, not a second transition engine. The host supplies its models, policies and domain constraints. The executable definition in [examples/review.php](../examples/review.php) is covered by the documentation lifecycle test.

## Transactional purchase approval

Seed the two-stage definition from the handbook. Assume a Purchase model with Flowable, a workspace_id, status and reviewer attribution columns. Resolve the subject in the authenticated workspace, authorize it, and start the flow inside the same transaction as the domain status change:

```php
use Illuminate\Support\Facades\{DB, Gate};
use MrNewport\LaravelFlow\FlowManager;

$instance = DB::transaction(function () use ($purchaseId, $workspaceId) {
    $purchase = Purchase::query()->where('workspace_id', $workspaceId)
        ->whereKey($purchaseId)->lockForUpdate()->firstOrFail();
    Gate::authorize('submit', $purchase);
    if ($purchase->status !== 'draft') {
        throw new DomainException('Purchase is not a draft.');
    }
    $instance = FlowManager::startFlow('purchase.draft.v1', $purchase);
    FlowManager::actionStep($instance->steps()->whereNull('finished_at')->sole(), 'submit');
    $purchase->status = 'review';
    $purchase->saveOrFail();
    return $instance;
});
```

The domain status guard under the subject lock prevents a duplicate submission in this example. Native Flow does not enforce one instance per subject. For request replay and immutable receipts use a host idempotency contract or Workflow.

Approval resolves the exact instance belonging to that subject, then scopes the chosen pending step to it:

```php
DB::transaction(function () use ($purchaseId, $workspaceId, $instanceId, $stepId, $actor) {
    $purchase = Purchase::query()->where('workspace_id', $workspaceId)
        ->whereKey($purchaseId)->lockForUpdate()->firstOrFail();
    Gate::authorize('approve', $purchase);
    $instance = $purchase->flowInstance()->whereKey($instanceId)->firstOrFail();
    $step = $instance->steps()->whereKey($stepId)->whereNull('finished_at')->firstOrFail();
    if ($purchase->status !== 'review') {
        throw new DomainException('Purchase has changed.');
    }
    FlowManager::actionStep($step, 'approve');
    $purchase->status = 'approved';
    $purchase->approved_by = $actor->getKey();
    $purchase->saveOrFail();
    // Write any durable host delivery-outbox row here, with a unique logical key.
});
```

Keep lock order consistent across host services to avoid avoidable deadlocks. Every write must use the same connection. A failed domain save or observer veto rolls back the Flow action. Do not send mail or call a purchasing API inside the transaction. Handle an uncertain response by checking the committed domain/step state or an existing receipt, not by starting another flow.

## Independent legal and finance reviews

An action can fork by defining two transition rows:

```php
foreach (['parallel.submit.v1', 'parallel.legal.v1', 'parallel.finance.v1'] as $id) {
    FlowStep::firstOrCreate(['id' => $id]);
}
foreach (['parallel.legal.v1', 'parallel.finance.v1'] as $target) {
    FlowTransition::firstOrCreate([
        'step_id' => 'parallel.submit.v1', 'action' => 'submit', 'next_step_id' => $target,
    ]);
    FlowTransition::firstOrCreate(['step_id' => $target, 'action' => 'approve', 'next_step_id' => 'END']);
}
$instance = FlowManager::startFlow('parallel.submit.v1', $purchase);
$branches = FlowManager::actionStep($instance->steps()->sole(), 'submit');
```

Authorize each branch by exact step ID and domain policy. Completing legal changes the convenience pointer to END, while finance remains pending:

```php
$legal = $instance->steps()->where('step_id', 'parallel.legal.v1')->whereNull('finished_at')->sole();
FlowManager::actionStep($legal, 'approve');
$stillPending = $instance->steps()->whereNull('finished_at')->get(); // finance
$isComplete = $stillPending->isEmpty(); // false
```

For an aggregate decision, serialize domain completion under a host subject/aggregate lock and verify every required branch's outcome. Do not create a shared successor hoping Flow will join it: it creates a successor for each transition. Workflows needing strict graph validation/versioned outcomes can use Workflow; its run status also derives from all pending steps. Neither native Flow nor Workflow invents a quorum or a join policy.

## Custom recipient routing with the existing strategy contract

Suppose each purchase must route to its workspace's current reviewer. Store the definition with `assignment_strategy = null` so the static native factory is not asked to resolve an unsupported custom name. Put reviewer selection in a host factory and reuse SingleUserStrategy for the write:

```php
namespace App\Workflow;

use MrNewport\LaravelFlow\Assignments\{AssignmentStrategyInterface, SingleUserStrategy};

final class PurchaseAssignments
{
    public function forPurchase(\App\Models\Purchase $purchase): AssignmentStrategyInterface
    {
        $reviewer = $purchase->workspace->reviewers()->where('active', true)->orderBy('id')->firstOrFail();
        return new SingleUserStrategy(['user_id' => $reviewer->getKey()]);
    }
}
```

Use it while the outer transaction is open:

```php
$instance = DB::transaction(function () use ($purchase, $routing) {
    $instance = FlowManager::startFlow('purchase.review.v1', $purchase);
    $routing->forPurchase($purchase)->assign($instance->steps()->sole());
    return $instance;
});
```

In this recipe ensure `purchase.review.v1` is seeded without a built-in assignment strategy. For successor assignment, loop the nonnull `actionStep()` results and route each required step before the outer transaction commits. Policy must verify the destination's workspace/eligibility under the host's appropriate lock or version check. Returned strategies implement the public interface; a domain-specific implementation can reuse AssignmentValues/AssignmentMutation rather than copying persistence logic. A service-container binding cannot replace FlowManager's static built-in factory calls. Custom email-list reassignment should go through a host-authorized service; the native email strategy intentionally denies manager reassignment.

## Fields and Workflow composition

Choose only the responsibilities you need:

| Need | Package path |
| --- | --- |
| Steps, transitions, assignee rows, transaction rollback, after-commit transition events | Native Flow alone. |
| Trusted form fields, Blade or optional Filament5, normalization, CSV/PDF display adapters | Fields, independent of Flow. |
| Scoped runs, immutable definition publication, host policy contracts, command/version replay and history | Workflow calling Flow. |
| Fields validation of a Workflow action | Workflow's optional FieldsActionValidator bridge. |
| Document records/templates/PDF/signature adapters | DocSign; separate authorization/storage/delivery contracts. |

A native action can use Fields directly:

```php
use MrNewport\LaravelFields\{Field, Schema};

$schema = Schema::make([
    Field::text('decision_note')->required()->rules(['max:2000']),
    Field::decimal('approved_limit', 2)->required()->rules(['min:0']),
]);
$validated = $schema->validate($request->all());
```

Apply those values in the same host transaction as the authorized Flow action. Fields supplies data validation, not actor or instance authorization. Use a private upload action outside its JSON workflow payload when needed.

With Workflow, register the same schema by definition identity and local step/action in an application service provider:

```php
use MrNewport\LaravelWorkflow\Contracts\ActionValidator;
use MrNewport\LaravelWorkflow\Validation\FieldsActionValidator;

$this->app->instance(ActionValidator::class, (new FieldsActionValidator)
    ->register('purchase-review', 1, 'review', 'approve', $schema));
```

Define/publish the matching Workflow Definition; bind its WorkflowAuthorizer and AssignmentAuthorizer (defaults deny), then use `WorkflowManager::start()`/`act()` with trusted scope/actor, retained idempotency key and expected version. Do not mix direct native mutations into a Workflow-managed graph: the coordination layer checks its published engine rows and expects its own receipt/version transaction. The Workflow handbook has the complete lifecycle and import guide; Fields' handbook has a working standalone/Filament form. Installing those packages without exercising these calls is not an integration.

## Durable notification delivery

An after-commit event listener is appropriate for best-effort UI updates. Reliable external delivery needs a durable host record. Inside the transaction that approves a purchase, create an outbox row unique on a stable logical key such as `purchase:<id>:approval:<revision>`, including only the necessary subject reference. A worker reloads authorized recipient/domain data, records an attempt, sends through the configured provider, and marks completion. Use provider idempotency where available and reconcile attempts after process failure.

Native Flow does not store such outbox records and a listener can be missed after commit. Re-running `actionStep()` to retry notification fails on completed state and would be the wrong recovery unit. If Workflow is present, a command receipt ID is a useful stable outbox key. Keep secret form contents, email addresses and provider tokens out of broad logs and notification subjects.
