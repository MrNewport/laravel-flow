<?php

namespace MrNewport\LaravelFlow\Assignments;

use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelFlow\Models\FlowStepAssignee;

class EmailListStrategy implements AssignmentStrategyInterface
{
    public function __construct(protected array $params=[])
    {
    }

    public function assign(FlowInstanceStep $instanceStep): void
    {
        foreach(($this->params['emails']??[]) as $email){
            FlowStepAssignee::create([
                'flow_instance_step_id'=>$instanceStep->id,
                'assignee_type'=>'email',
                'assignee_value'=>$email
            ]);
        }
    }

    public function canReassign(FlowInstanceStep $instanceStep, $currentUser): bool
    {
        return false;
    }

    public function reassign(FlowInstanceStep $instanceStep, array $newAssignees): void
    {
        $instanceStep->assignees()->delete();
        foreach($newAssignees as $email){
            FlowStepAssignee::create([
                'flow_instance_step_id'=>$instanceStep->id,
                'assignee_type'=>'email',
                'assignee_value'=>$email
            ]);
        }
    }
}
