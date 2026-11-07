<?php

namespace MrNewport\LaravelFlow\Assignments;

use MrNewport\LaravelFlow\Models\FlowInstanceStep;

class EmailListStrategy implements AssignmentStrategyInterface
{
    public function __construct(protected array $params = []) {}

    public function assign(FlowInstanceStep $instanceStep): void
    {
        AssignmentMutation::write($instanceStep, 'email', AssignmentValues::emails($this->params['emails'] ?? []), false);
    }

    public function canReassign(FlowInstanceStep $instanceStep, $currentUser): bool
    {
        return false;
    }

    public function reassign(FlowInstanceStep $instanceStep, array $newAssignees): void
    {
        AssignmentMutation::write($instanceStep, 'email', AssignmentValues::emails($newAssignees), true);
    }
}
