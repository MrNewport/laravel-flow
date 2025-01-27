<?php

namespace MrNewport\LaravelFlow\Assignments;

use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelFlow\Models\FlowStepAssignee;

class SingleUserStrategy implements AssignmentStrategyInterface
{
    public function __construct(protected array $params=[])
    {
    }

    public function assign(FlowInstanceStep $instanceStep): void
    {
        if(isset($this->params['user_id'])){
            FlowStepAssignee::create([
                'flow_instance_step_id' => $instanceStep->id,
                'assignee_type'         => 'user',
                'assignee_value'        => (string)$this->params['user_id']
            ]);
        }
    }

    public function canReassign(FlowInstanceStep $instanceStep, $currentUser): bool
    {
        return $instanceStep->assignees()
            ->where('assignee_type','user')
            ->where('assignee_value',(string)$currentUser->id)
            ->exists();
    }

    public function reassign(FlowInstanceStep $instanceStep, array $newAssignees): void
    {
        $instanceStep->assignees()->delete();
        if(!empty($newAssignees)){
            FlowStepAssignee::create([
                'flow_instance_step_id'=>$instanceStep->id,
                'assignee_type'=>'user',
                'assignee_value'=>(string)$newAssignees[0]
            ]);
        }
    }
}
