
# mrnewport/laravel-flow

A **domain-agnostic** flow/workflow package for Laravel. It allows:

- **FlowStep** definitions with optional assignment strategies and notifications.
- **FlowTransition** actions that link one step to the next.
- **FlowInstance** tracking each entity’s position in the flow.
- **FlowInstanceStep** logging each step taken by that entity.
- A **pivot-based assignment** table (`flow_step_assignees`), so you can assign one or many users or external emails.
- **Reassign** logic if someone else needs to handle the step.
- **Multi-step** transitions (one action can lead to multiple next steps).
- **Laravel events** and notifications for each action.

Everything is **infinitely expandable**—with no forced domain or role logic. Perfect for multi-approval flows, complex multi-step processes, or advanced e-sign style flows.

---

## Table of Contents

1. [Requirements](#requirements)  
2. [Installation](#installation)  
3. [Configuration](#configuration)  
4. [Database Structure](#database-structure)  
5. [Core Concepts](#core-concepts)  
   - [FlowStep](#flowstep)  
   - [FlowTransition](#flowtransition)  
   - [FlowInstance](#flowinstance)  
   - [FlowInstanceStep](#flowinstancestep)  
   - [FlowStepAssignee](#flowstepassignee)  
6. [Assignment Strategies](#assignment-strategies)  
   - [SingleUserStrategy](#singleuserstrategy)  
   - [MultiUserStrategy](#multiuserstrategy)  
   - [EmailListStrategy](#emailliststrategy)  
   - [Reassigning a Step](#reassigning-a-step)  
7. [Using the FlowManager](#using-the-flowmanager)  
   - [Multi-Step Example with More Steps](#multi-step-example-with-more-steps)  
   - [Starting a Flow](#starting-a-flow)  
   - [Completing a Step with an Action](#completing-a-step-with-an-action)  
   - [Multiple Transitions](#multiple-transitions)  
   - [End Step](#end-step)  
   - [Events](#events)  
   - [Notifications](#notifications)  
8. [Console Commands](#console-commands)  
   - [DefineStepCommand](#definestepcommand)  
9. [Flowable Trait](#flowable-trait)  
10. [Advanced Customization](#advanced-customization)  
    - [1. Custom Assignment Strategies](#1-custom-assignment-strategies)  
    - [2. Event Listeners for Step+Action Logic](#2-event-listeners-for-stepaction-logic)  
    - [3. Larger Example: 6+ Steps, Complex Branching](#3-larger-example-6-steps-complex-branching)  
    - [4. Rejection Actions and External Approvals](#4-rejection-actions-and-external-approvals)  
    - [5. Integrating with External Services](#5-integrating-with-external-services)  
    - [6. Additional Notifications & Channels](#6-additional-notifications--channels)  
11. [Testing](#testing)  
12. [License](#license)  

---

## Requirements

- **Laravel** ^11.0  
- **PHP** ^8.1  
- **Illuminate** (events, notifications, database)  
- **spatie/laravel-package-tools** ^1.9  

---

## Installation

1. **Install** via Composer:
   ```bash
   composer require mrnewport/laravel-flow
   ```

2. (Optional) **Publish** config & stubs:
   ```bash
   php artisan vendor:publish --provider="MrNewport\\LaravelFlow\\FlowServiceProvider" --tag=flow-config
   php artisan vendor:publish --provider="MrNewport\\LaravelFlow\\FlowServiceProvider" --tag=flow-stubs
   ```

3. **Migrate**:
   ```bash
   php artisan migrate
   ```
   This creates `flow_steps`, `flow_transitions`, `flow_instances`, `flow_instance_steps`, and `flow_step_assignees`.

---

## Configuration

After publishing, open `config/flow.php`:
```php
return [
    'user_model' => \App\Models\User::class,
    'rejection_actions' => ['reject','cancel'],
];
```
- **`user_model`**: If your assignment strategies reference Eloquent users, update here.
- **`rejection_actions`**: Flow actions that count as rejections/cancellations.

---

## Database Structure

1. **`flow_steps`**: Each distinct step.
    - `id`: The string identifier.
    - `name`: A descriptive title.
    - `notify`: If true, the system can automatically handle notifications.
    - `assignment_strategy`: e.g. `'single_user'`, `'multi_user'`, `'email_list'`
    - `assignment_params`: JSON object for that strategy.

2. **`flow_transitions`**: Action-based transitions.
    - `step_id`: The current step’s ID.
    - `action`: e.g. `'approve'`, `'reject'`, `'submit'`.
    - `next_step_id`: The next step triggered by that action.

3. **`flow_instances`**: Tracks which step a particular model is on.
    - `current_step_id`: The step the entity is currently at.
    - `model_type`, `model_id`: Polymorphic references to your Eloquent model (e.g. `App\Models\Post`).

4. **`flow_instance_steps`**: Log of each step in a particular instance.
    - `flow_instance_id`: references the `flow_instances` row.
    - `step_id`: references `flow_steps`.
    - `action_taken`: the action used to complete the step, if any.
    - `started_at`, `finished_at`: timestamps.

5. **`flow_step_assignees`**: Pivot storing who must do a step.
    - `flow_instance_step_id`: The instance step that’s assigned.
    - `assignee_type`: `'user'`, `'email'`, `'token'`, etc.
    - `assignee_value`: The user ID, email address, or external reference.

---

## Core Concepts

### FlowStep

An individual step in your flow. Example IDs: `'draft_review'`, `'supplier_upload'`, `'marketing_approval'`. Each step can specify:

- **`notify`**: If `true`, the system or an event listener can trigger notifications automatically.
- **`assignment_strategy`, `assignment_params`**: e.g. `'single_user'` with `{"user_id":8}`.

### FlowTransition

Defines an **action** from `step_id` → `next_step_id`. For example, `(draft_review, 'approve', marketing_approval)` means if user **approves** at `draft_review`, the next step is `marketing_approval`.

### FlowInstance

Records which step a **specific** entity (like a post, application, or user-submitted form) is currently at. Also references all steps that have been completed in `flow_instance_steps`.

### FlowInstanceStep

A record of a step within a FlowInstance. We store the timestamps and the action that completed it. If the step has an assignment strategy, it’s also assigned to users/emails in `flow_step_assignees`.

### FlowStepAssignee

Pivot that stores exactly who’s assigned to a step. For instance, `'assignee_type=user'`, `'assignee_value=3'` means user #3 must handle it.

---

## Assignment Strategies

The package includes **SingleUserStrategy**, **MultiUserStrategy**, and **EmailListStrategy**. They all implement `AssignmentStrategyInterface`, auto-populating `flow_step_assignees` whenever a new step is created.

#### SingleUserStrategy
- `assignment_params={"user_id":7}`
- Creates a single pivot row: `(flow_instance_step_id, 'user', '7')`.

#### MultiUserStrategy
- `assignment_params={"user_ids":[7,8]}`
- Creates multiple pivot rows for each user ID.

#### EmailListStrategy
- `assignment_params={"emails":["foo@bar.com","hello@domain.io"]}`
- Creates pivot rows with `'assignee_type=email'`, `'assignee_value="foo@bar.com"` etc.

### Reassigning a Step

Use:
```php
FlowManager::reassignStep($stepInstance, $currentUser, $newAssignees);
```
- Checks if the strategy’s `canReassign($step, $currentUser)` is true, then calls `reassign($step, $newAssignees)`.
- If it’s single-user strategy, we remove the old assignment, add the new. If multi-user, we remove or update accordingly.

---

## Using the FlowManager

Below are typical calls to **`FlowManager`**.

### Multi-Step Example with More Steps

Let’s define these steps:

1. **`start_request`**
2. **`manager_review`**
3. **`director_approval`**
4. **`supplier_upload`**
5. **`marketing_check`**
6. **`quality_assurance`**
7. **`END`**

We can have transitions like:

- `(start_request, 'submit', manager_review)`
- `(manager_review, 'reject', start_request)`
- `(manager_review, 'approve', director_approval)`
- `(director_approval, 'approve', supplier_upload)`
- `(director_approval, 'reject', manager_review)`
- `(supplier_upload, 'upload_done', marketing_check)`
- `(marketing_check, 'changes_needed', supplier_upload)`
- `(marketing_check, 'approve', quality_assurance)`
- `(quality_assurance, 'approve', END)`
- `(quality_assurance, 'reject', supplier_upload)`

This means your flow can bounce around multiple times if rejections occur.

### Starting a Flow

1. **Define** your step with `flow:define-step` or code. For example:
   ```bash
   php artisan flow:define-step start_request "Start Request" --strategy=single_user --params='{"user_id":3}'
   ```
   Next define transitions:
   ```bash
   php artisan flow:define-step start_request --actions="submit:manager_review"
   ```
2. In your code:
   ```php
   $entity = new SomeModel(...);
   $flowInstance = FlowManager::startFlow('start_request',$entity);

   // This creates a FlowInstance row + FlowInstanceStep row
   // If step "start_request" has single_user with user_id=3, it is assigned to user 3.
   ```

### Completing a Step with an Action

```php
$currentStep = $flowInstance->steps()
    ->whereNull('finished_at')
    ->first();

FlowManager::actionStep($currentStep,'submit');
```
- If `(start_request, 'submit') => manager_review` transition exists, a new `FlowInstanceStep` is created for `manager_review`, assigned if needed, and `current_step_id` updates to `'manager_review'`.

### Multiple Transitions

One `(step_id, action)` can lead to multiple `next_step_id`:

```php
flow:define-step multi_out --actions="done:stepX,done:stepY,done:stepZ"
```
Now calling:
```php
FlowManager::actionStep($multiOutStep, 'done');
```
Creates **three** new steps: `stepX`, `stepY`, `stepZ`.

### End Step

If a transition’s `next_step_id='END'`, that signals the flow is finished. `FlowInstance` is updated to `current_step_id='END'`, no further steps.

### Events

When you call:
```php
FlowManager::actionStep($oldStep,'approve');
```
a `FlowActionEvent($oldStep, 'approve', $newStepOrNull)` is fired. Use standard Laravel event listeners to add custom logic or extra notifications.

### Notifications

If `flow_steps.notify==true`, you can have your listeners automatically send out `FlowBaseNotification` or custom notifications to the assigned users/emails. Typically:

- Check `flow_step_assignees` for that step.
- If `'assignee_type=user'`, load the user’s Eloquent record from `config('flow.user_model')`.
- If `'assignee_type=email'`, route a mail-based notification.

---

## Console Commands

### DefineStepCommand

Use this command to create or update a step and define transitions in one shot:
```bash
php artisan flow:define-step manager_review "Manager Review" \
    --notify=true \
    --strategy=multi_user \
    --params='{"user_ids":[3,5,8]}' \
    --actions="approve:director_approval,reject:start_request"
```
Prints lines for each transition, then:
```
Step [manager_review] defined/updated successfully.
```

---

## Flowable Trait

If you want to manage flows directly from an Eloquent model, you can:

```php
use MrNewport\LaravelFlow\Traits\Flowable;

class Document extends Model
{
    use Flowable;
}
```

Then:

```php
$doc = Document::create([...]);
$doc->startFlow('start_request');

$current = $doc->currentFlowStep();
if($current) {
    $doc->flowAction('submit');
}
```

No direct calls to `FlowManager`.

---

## Advanced Customization

Below are **expansion** ideas purely through your **own** code (events, custom classes).

### 1. Custom Assignment Strategies

If your domain requires specialized logic—like a **Token** or **Slack** approach—create a class implementing `AssignmentStrategyInterface`:

```php
namespace App\FlowAssignments;

use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelFlow\Models\FlowStepAssignee;
use MrNewport\LaravelFlow\Assignments\AssignmentStrategyInterface;

class SlackChannelStrategy implements AssignmentStrategyInterface
{
    public function __construct(protected array $params=[])
    {
        // e.g. $params['channel'] => '#general'
    }

    public function assign(FlowInstanceStep $instanceStep): void
    {
        // store the Slack channel
        FlowStepAssignee::create([
            'flow_instance_step_id'=>$instanceStep->id,
            'assignee_type'=>'slack_channel',
            'assignee_value'=>$this->params['channel']
        ]);
    }

    public function canReassign(FlowInstanceStep $instanceStep, $currentUser): bool
    {
        // If you have logic for reassigning Slack channels, define here
        return false;
    }

    public function reassign(FlowInstanceStep $instanceStep, array $newAssignees): void
    {
        // Example: update the Slack channel
        $instanceStep->assignees()->delete();
        foreach($newAssignees as $chan){
            FlowStepAssignee::create([
                'flow_instance_step_id'=>$instanceStep->id,
                'assignee_type'=>'slack_channel',
                'assignee_value'=>$chan
            ]);
        }
    }
}
```

Then reference `'slack_channel'` in your step’s `assignment_strategy` and `'assignment_params'=>{"channel":"#my-team"}`.

### 2. Event Listeners for Step+Action Logic

Whenever `FlowManager::actionStep($oldStep, 'approve')` is called, a `FlowActionEvent` fires. You can define a listener:

```php
use MrNewport\LaravelFlow\Events\FlowActionEvent;

Event::listen(FlowActionEvent::class, function(FlowActionEvent $e){
  if($e->oldStep->step_id==='manager_review' && $e->action==='approve') {
    // E.g. log something or call external API
  }
});
```

No need to modify package code—**you** handle domain logic in your event listener.

### 3. Larger Example: 6+ Steps, Complex Branching

Suppose you define these steps: `stepA`, `stepB`, `stepC`, `stepD`, `stepE`, `stepF`, and `END`. You can do:

```
flow:define-step stepA "Step A" --actions="go:stepB,skip:stepC"
flow:define-step stepB "Step B" --actions="approve:stepD,reject:END"
flow:define-step stepC "Step C" --actions="done:stepD"
flow:define-step stepD "Step D" --actions="approve:stepE, reject:stepB"
flow:define-step stepE "Step E" --actions="all_good:stepF, revision:stepC"
flow:define-step stepF "Step F" --actions="finalize:END"
flow:define-step END "Flow End"
```

Now you have a multi-branch flow with looping between stepB/stepD if rejections happen, or skipping stepB by going stepA → skip → stepC. No domain constraints, purely structured in your transitions.

### 4. Rejection Actions and External Approvals

If you set `config('flow.rejection_actions')=['reject','cancel']`, any step completed with `'reject'` or `'cancel'` is considered a rejection. You can add a step for `'supplier_upload'` with transitions `'action=reject => rework_assets'`. That flow logic is fully under your control. If you want external e-sign or approval, store the token or link in `assignment_params` and let the user finalize externally.

### 5. Integrating with External Services

Each time a new step is created, you could:

- **Post** to Slack with the assigned user info.
- **Call** an external DocuSign or HelloSign API to request a signature.
- **Send** events to a microservice queue.

All done **in your** listeners or custom code that runs upon step creation.

### 6. Additional Notifications & Channels

**`FlowBaseNotification`** is a mail-based example. You can define:

- `FlowSlackNotification`: `via()` returns `[SlackChannel::class]`, posting a Slack message.
- `FlowSmsNotification`: using Twilio or Nexmo.
- **No** changes in the package are required. You just create these in your app code and send them from an event listener or a custom approach.

---

## Testing

A **comprehensive** test suite lives in `tests/`. Just run:

```bash
composer test
```

It covers:

- **`DefineStepCommandTest`**: verifying creation of steps & transitions with exact console output.
- **`FlowManagerBasicTest`**: tests `startFlow` & single-step transitions.
- **`FlowManagerAssignmentTest`**: ensures assignment & reassign logic works.
- **`FlowManagerMultiTransitionsTest`**: multiple next steps for one action.
- **`FlowEventTest`**: verifies `FlowActionEvent` is fired.
- **`FlowNotificationTest`**: demonstrates how notifications can be triggered for assigned users/emails.

---

## License

This package is open-sourced software licensed under the [MIT license](LICENSE). Enjoy building **fully** domain-agnostic flows for your Laravel application!
