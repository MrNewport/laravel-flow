<?php

namespace MrNewport\LaravelFlow\Traits;

use Illuminate\Database\Eloquent\Relations\HasOne;
use MrNewport\LaravelFlow\Models\FlowInstance;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelFlow\FlowManager;

trait Flowable
{
    public function flowInstance(): HasOne
    {
        return $this->hasOne(FlowInstance::class,'model_id')
            ->where('model_type','=',static::class);
    }

    public function startFlow(string $stepId)
    {
        return FlowManager::startFlow($stepId,$this);
    }

    public function currentFlowStep()
    {
        if($this->flowInstance){
            return $this->flowInstance->steps()
                ->whereNull('finished_at')
                ->first();
        }
        return null;
    }

    public function flowAction(string $action)
    {
        $step = $this->currentFlowStep();
        if($step){
            FlowManager::actionStep($step,$action);
        }
    }

    public function flowReassign(FlowInstanceStep $step, $currentUser, array $newAssignees)
    {
        return FlowManager::reassignStep($step,$currentUser,$newAssignees);
    }
}
