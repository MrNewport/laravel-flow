<?php

declare(strict_types=1);

namespace MrNewport\LaravelFlow\Assignments;

use LogicException;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelFlow\Models\FlowStepAssignee;
use MrNewport\LaravelFlow\Support\Persistence;
use MrNewport\LaravelFlow\Support\SubjectIdentity;
use MrNewport\LaravelFlow\Support\Text;

/** Built-in mutations are atomic even when called directly by an adapter. */
final class AssignmentMutation
{
    /** @param list<string> $identities */
    public static function write(FlowInstanceStep $step, string $type, array $identities, bool $replace): void
    {
        SubjectIdentity::assertConnection($step);
        $step->getConnection()->transaction(function () use ($step, $type, $identities, $replace): void {
            $locked = $step->newQuery()->lockForUpdate()->findOrFail($step->getKey());
            if ($locked->finished_at !== null) {
                throw new LogicException(Text::get('errors.a_completed_workflow_step_cannot_be_reassigned'));
            }
            if ($replace) {
                $locked->assignees()->delete();
            }
            foreach ($identities as $identity) {
                Persistence::created(FlowStepAssignee::create([
                    'flow_instance_step_id' => $locked->getKey(),
                    'assignee_type' => $type,
                    'assignee_value' => $identity,
                ]));
            }
        });
    }

    public static function containsUser(FlowInstanceStep $step, mixed $user): bool
    {
        $identity = AssignmentValues::user($user);
        if ($identity === null) {
            return false;
        }

        // Opaque keys are byte-sensitive, independent of the database collation.
        return $step->assignees()->where('assignee_type', 'user')->sharedLock()
            ->get(['assignee_type', 'assignee_value'])
            ->contains(fn ($assignment): bool => $assignment->assignee_type === 'user' && $assignment->assignee_value === $identity);
    }
}
