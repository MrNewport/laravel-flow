<?php

namespace MrNewport\LaravelFlow;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use MrNewport\LaravelFlow\Assignments\AssignmentStrategyFactory;
use MrNewport\LaravelFlow\Events\FlowActionEvent;
use MrNewport\LaravelFlow\Models\FlowInstance;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Models\FlowTransition;
use MrNewport\LaravelFlow\Support\Limits;
use MrNewport\LaravelFlow\Support\Persistence;
use MrNewport\LaravelFlow\Support\SubjectIdentity;
use MrNewport\LaravelFlow\Support\Text;

class FlowManager
{
    /**
     * Start a new flow instance on the given step for an entity
     * Auto-assign if step has assignment_strategy/params
     */
    public static function startFlow(string $stepId, $entity): FlowInstance
    {
        self::identifier($stepId, 'step id');
        if ($stepId === 'END') {
            throw new \InvalidArgumentException(Text::get('errors.end_is_a_completion_target_not_an_initial_step'));
        }
        [$subjectType, $subjectKey] = SubjectIdentity::resolve($entity);

        return (new FlowInstance)->getConnection()->transaction(function () use ($stepId, $subjectType, $subjectKey) {
            // A locking read sees definitions committed after an outer MySQL snapshot.
            $step = FlowStep::query()->sharedLock()->findOrFail($stepId);
            if ($step->getKey() === 'END') {
                throw new \InvalidArgumentException(Text::get('errors.end_is_a_completion_target_not_an_initial_step'));
            }
            $instance = Persistence::created(FlowInstance::create([
                'current_step_id' => $step->getKey(),
                'model_type' => $subjectType,
                'model_id' => $subjectKey,
            ]));

            $instanceStep = Persistence::created(FlowInstanceStep::create([
                'flow_instance_id' => $instance->id,
                'step_id' => $step->getKey(),
            ]));

            if ($step->assignment_strategy) {
                $strategy = AssignmentStrategyFactory::make($step->assignment_strategy, $step->assignment_params ?? []);
                $strategy->assign($instanceStep);
            }

            return $instance;
        });
    }

    /**
     * Complete the current step with an action, create next steps, handle assignment & events
     */
    public static function actionStep(FlowInstanceStep $currentStep, string $action): array
    {
        self::identifier($action, 'action');
        SubjectIdentity::assertConnection($currentStep);

        return $currentStep->getConnection()->transaction(function () use ($currentStep, $action) {
            $currentStep = $currentStep->newQuery()->lockForUpdate()->findOrFail($currentStep->getKey());
            if ($currentStep->finished_at !== null) {
                throw new \LogicException(Text::get('errors.this_workflow_step_is_already_completed'));
            }

            $maximum = Limits::value('max_transitions_per_action', 1000);
            $transitions = FlowTransition::where('step_id', $currentStep->step_id)
                ->where('action', $action)->limit($maximum + 1)->sharedLock()->get();
            if ($transitions->count() > $maximum) {
                throw new \InvalidArgumentException(Text::get('errors.the_action_exceeds_flow_max_transitions_per_action'));
            }

            if ($transitions->isEmpty()) {
                throw new \InvalidArgumentException(Text::get('errors.no_transition_is_defined_for_this_workflow_action'));
            }

            $nextDefinitions = new Collection;
            $nextIds = array_values(array_unique(array_filter($transitions->pluck('next_step_id')->all(), fn ($id) => $id !== 'END')));
            foreach (array_chunk($nextIds, 500) as $ids) {
                $nextDefinitions = $nextDefinitions->merge(FlowStep::query()->whereIn('id', $ids)->sharedLock()->get());
            }
            $nextDefinitions = $nextDefinitions->keyBy('id');

            // Resolve all targets before any write. A collation alias may resolve to END.
            foreach ($transitions as $transition) {
                if ($transition->next_step_id === 'END') {
                    continue;
                }
                $definition = $nextDefinitions->get($transition->next_step_id)
                    ?? FlowStep::query()->sharedLock()->findOrFail($transition->next_step_id);
                if ($definition->getKey() === 'END') {
                    throw new \InvalidArgumentException(Text::get('errors.use_the_exact_end_completion_target'));
                }
                $nextDefinitions->put($transition->next_step_id, $definition);
            }

            $instance = FlowInstance::query()->lockForUpdate()->findOrFail($currentStep->flow_instance_id);
            $currentStep->completeStep($action);

            $createdSteps = [];
            foreach ($transitions as $tx) {
                if ($tx->next_step_id === 'END') {
                    Event::dispatch(new FlowActionEvent($currentStep, $action, null));
                    $createdSteps[] = null;
                } else {
                    // Preserve database-collation lookup semantics for existing noncanonical IDs.
                    $nextStep = $nextDefinitions->get($tx->next_step_id)
                        ?? FlowStep::query()->sharedLock()->findOrFail($tx->next_step_id);
                    $newStepRecord = Persistence::created(FlowInstanceStep::create([
                        'flow_instance_id' => $instance->id,
                        'step_id' => $tx->next_step_id,
                    ]));

                    if ($nextStep->assignment_strategy) {
                        $strategy = AssignmentStrategyFactory::make(
                            $nextStep->assignment_strategy,
                            $nextStep->assignment_params ?? []
                        );
                        $strategy->assign($newStepRecord);
                    }

                    Event::dispatch(new FlowActionEvent($currentStep, $action, $newStepRecord));
                    $createdSteps[] = $newStepRecord;
                }
            }

            $instance->current_step_id = $transitions->last()->next_step_id;
            Persistence::save($instance);

            return $createdSteps;
        });
    }

    /**
     * Reassign logic using the step's assignment strategy
     */
    public static function reassignStep(FlowInstanceStep $step, $currentUser, array $newAssignees, $subject = null): bool
    {
        SubjectIdentity::assertConnection($step);

        $identity = $subject === null ? null : SubjectIdentity::resolve($subject);

        return $step->getConnection()->transaction(function () use ($step, $currentUser, $newAssignees, $subject, $identity): bool {
            $step = $step->newQuery()->lockForUpdate()->findOrFail($step->getKey());
            if ($identity !== null) {
                $instance = FlowInstance::query()->lockForUpdate()->findOrFail($step->flow_instance_id);
                if (! in_array($instance->model_type, [$identity[0], get_class($subject)], true)
                    || (string) $instance->model_id !== (string) $identity[1]) {
                    throw new \InvalidArgumentException(Text::get('errors.the_workflow_step_does_not_belong_to_this_subject'));
                }
            }
            if ($step->finished_at !== null) {
                throw new \LogicException(Text::get('errors.a_completed_workflow_step_cannot_be_reassigned'));
            }
            $theStep = FlowStep::query()->sharedLock()->findOrFail($step->step_id);
            if (! $theStep->assignment_strategy) {
                return false;
            }
            $strategy = AssignmentStrategyFactory::make($theStep->assignment_strategy, $theStep->assignment_params ?? []);
            if (! $strategy->canReassign($step, $currentUser)) {
                return false;
            }
            $strategy->reassign($step, $newAssignees);

            return true;
        });
    }

    private static function identifier(string $value, string $label): void
    {
        if (trim($value) === '' || strlen($value) > 100 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new \InvalidArgumentException(Text::get('errors.identifier', ['label' => $label]));
        }
    }
}
