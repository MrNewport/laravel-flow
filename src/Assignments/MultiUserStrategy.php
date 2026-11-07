<?php

namespace MrNewport\LaravelFlow\Assignments;

use MrNewport\LaravelFlow\Models\FlowInstanceStep;

class MultiUserStrategy implements AssignmentStrategyInterface
{
    public function __construct(protected array $params = []) {}

    public function assign(FlowInstanceStep $instanceStep): void
    {
        AssignmentMutation::write($instanceStep, 'user', AssignmentValues::users($this->params['user_ids'] ?? []), false);
    }

    public function canReassign(FlowInstanceStep $instanceStep, $currentUser): bool
    {
        return AssignmentMutation::containsUser($instanceStep, $currentUser);
    }

    public function reassign(FlowInstanceStep $instanceStep, array $newAssignees): void
    {
        AssignmentMutation::write($instanceStep, 'user', AssignmentValues::users($newAssignees), true);
    }
}
