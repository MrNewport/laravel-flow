<?php

namespace MrNewport\LaravelFlow\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\FlowInstance;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;

/** @phpstan-require-extends Model */
trait Flowable
{
    /** @return HasOne<FlowInstance, $this> */
    public function flowInstance(): HasOne
    {
        return $this->hasOne(FlowInstance::class, 'model_id')
            ->whereIn('model_type', array_unique([(string) $this->getMorphClass(), static::class]));
    }

    public function startFlow(string $stepId)
    {
        $instance = FlowManager::startFlow($stepId, $this);
        $this->unsetRelation('flowInstance');

        return $instance;
    }

    public function currentFlowStep(): ?FlowInstanceStep
    {
        $instance = $this->flowInstance()->first();
        if ($instance) {
            return $instance->steps()
                ->whereNull('finished_at')
                ->orderBy('id')
                ->first();
        }

        return null;
    }

    public function flowAction(string $action)
    {
        $step = $this->currentFlowStep();
        if ($step) {
            FlowManager::actionStep($step, $action);
        }
    }

    public function flowReassign(FlowInstanceStep $step, $currentUser, array $newAssignees)
    {
        return FlowManager::reassignStep($step, $currentUser, $newAssignees, $this);
    }
}
