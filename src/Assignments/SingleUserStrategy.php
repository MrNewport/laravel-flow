<?php

namespace MrNewport\LaravelFlow\Assignments;

use MrNewport\LaravelFlow\Models\FlowInstanceStep;

class SingleUserStrategy implements AssignmentStrategyInterface
{
    public function __construct(protected array $params = []) {}

    public function assign(FlowInstanceStep $instanceStep): void
    {
        $identities = isset($this->params['user_id']) ? AssignmentValues::users([$this->params['user_id']]) : [];
        AssignmentMutation::write($instanceStep, 'user', $identities, false);
    }

    public function canReassign(FlowInstanceStep $instanceStep, $currentUser): bool
    {
        return AssignmentMutation::containsUser($instanceStep, $currentUser);
    }

    public function reassign(FlowInstanceStep $instanceStep, array $newAssignees): void
    {
        // Retain the single-user API's first-selection convention, validating the whole list.
        $identities = AssignmentValues::users($newAssignees);
        AssignmentMutation::write($instanceStep, 'user', array_slice($identities, 0, 1), true);
    }
}
