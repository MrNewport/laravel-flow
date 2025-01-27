<?php

namespace MrNewport\LaravelFlow\Assignments;

use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelFlow\Models\FlowStepAssignee;

class MultiUserStrategy implements AssignmentStrategyInterface
{
    public function __construct(protected array $params=[])
    {
    }

    public function assign(FlowInstanceStep $instanceStep): void
    {
        foreach(($this->params['user_ids']??[]) as $uid){
            FlowStepAssignee::create([
                'flow_instance_step_id'=>$instanceStep->id,
                'assignee_type'=>'user',
                'assignee_value'=>(string)$uid
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
        foreach($newAssignees as $uid){
            FlowStepAssignee::create([
                'flow_instance_step_id'=>$instanceStep->id,
                'assignee_type'=>'user',
                'assignee_value'=>(string)$uid
            ]);
        }
    }
}
