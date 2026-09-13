<?php

namespace MrNewport\LaravelFlow;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use MrNewport\LaravelFlow\Assignments\AssignmentStrategyFactory;
use MrNewport\LaravelFlow\Events\FlowActionEvent;
use MrNewport\LaravelFlow\Models\{
    FlowStep,
    FlowTransition,
    FlowInstance,
    FlowInstanceStep,
    FlowStepAssignee
};

class FlowManager
{
    /**
     * Start a new flow instance on the given step for an entity
     * Auto-assign if step has assignment_strategy/params
     */
    public static function startFlow(string $stepId, $entity): FlowInstance
    {
        return (new FlowInstance())->getConnection()->transaction(function () use ($stepId, $entity) {
            $instance = FlowInstance::create([
                'current_step_id' => $stepId,
                'model_type'      => get_class($entity),
                'model_id'        => $entity->getKey()
            ]);

            $step = FlowStep::findOrFail($stepId);

            $instanceStep = FlowInstanceStep::create([
                'flow_instance_id'=>$instance->id,
                'step_id'=>$stepId
            ]);

            if($step->assignment_strategy) {
                $strategy = AssignmentStrategyFactory::make($step->assignment_strategy,$step->assignment_params??[]);
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
        return $currentStep->getConnection()->transaction(function () use ($currentStep, $action) {
            $currentStep = $currentStep->newQuery()->lockForUpdate()->findOrFail($currentStep->getKey());
            if ($currentStep->finished_at !== null) {
                throw new \LogicException('This workflow step is already completed.');
            }

            $currentStep->completeStep($action);
            $instance = $currentStep->flowInstance;

            $transitions = FlowTransition::where('step_id',$currentStep->step_id)
                ->where('action',$action)->get();

            if($transitions->isEmpty()) {
                return [];
            }

            $createdSteps = [];
            foreach($transitions as $tx) {
                if($tx->next_step_id==='END') {
                    $instance->update(['current_step_id'=>'END']);
                    Event::dispatch(new FlowActionEvent($currentStep,$action,null));
                    $createdSteps[] = null;
                } else {
                    $nextStep = FlowStep::findOrFail($tx->next_step_id);
                    $newStepRecord = FlowInstanceStep::create([
                        'flow_instance_id'=>$instance->id,
                        'step_id'=>$tx->next_step_id
                    ]);
                    $instance->update(['current_step_id'=>$tx->next_step_id]);

                    if($nextStep->assignment_strategy) {
                        $strategy = AssignmentStrategyFactory::make(
                            $nextStep->assignment_strategy,
                            $nextStep->assignment_params??[]
                        );
                        $strategy->assign($newStepRecord);
                    }

                    if($nextStep->notify) {
                        // You can fire notifications for new step
                        // e.g. Notification::send(...)
                    }

                    Event::dispatch(new FlowActionEvent($currentStep,$action,$newStepRecord));
                    $createdSteps[] = $newStepRecord;
                }
            }

            return $createdSteps;
        });
    }

    /**
     * Reassign logic using the step's assignment strategy
     */
    public static function reassignStep(FlowInstanceStep $step, $currentUser, array $newAssignees): bool
    {
        $theStep = FlowStep::findOrFail($step->step_id);
        if(! $theStep->assignment_strategy) {
            return false;
        }
        $strategy = AssignmentStrategyFactory::make($theStep->assignment_strategy,$theStep->assignment_params??[]);
        if(! $strategy->canReassign($step,$currentUser)) {
            return false;
        }
        $strategy->reassign($step, $newAssignees);
        return true;
    }
}
