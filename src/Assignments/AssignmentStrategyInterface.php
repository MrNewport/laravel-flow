<?php

namespace MrNewport\LaravelFlow\Assignments;

use MrNewport\LaravelFlow\Models\FlowInstanceStep;

interface AssignmentStrategyInterface
{
    public function assign(FlowInstanceStep $instanceStep): void;
    public function canReassign(FlowInstanceStep $instanceStep, $currentUser): bool;
    public function reassign(FlowInstanceStep $instanceStep, array $newAssignees): void;
}
