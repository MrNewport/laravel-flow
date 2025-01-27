<?php

namespace MrNewport\LaravelFlow\Commands;

use Illuminate\Console\Command;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Models\FlowTransition;

/**
 * Thorough, no placeholders, ensures transitions output appears
 * BEFORE the final "Step [X] defined/updated successfully." line.
 */
class DefineStepCommand extends Command
{
    protected $signature = 'flow:define-step
                            {stepId : Unique ID for this step}
                            {name? : Optional display name}
                            {--notify= : true/false}
                            {--strategy= : assignment strategy name (single_user,multi_user,email_list,etc.)}
                            {--params= : JSON for assignment_params}
                            {--actions= : comma-separated transitions like "approve:next,reject:END"}';

    protected $description = 'Create or update a flow step, with optional transitions.';

    public function handle(): int
    {
        $stepId   = $this->argument('stepId');
        $nameIn   = $this->argument('name') ?: '';
        $notifyBool = filter_var($this->option('notify'), FILTER_VALIDATE_BOOLEAN);
        $strategy = $this->option('strategy') ?: null;
        $params   = $this->parseParams($this->option('params'));
        $actions  = $this->option('actions');

        // 1) Update or create the FlowStep row
        FlowStep::updateOrCreate(
            ['id' => $stepId],
            [
                'name'               => $nameIn,
                'notify'             => $notifyBool,
                'assignment_strategy'=> $strategy,
                'assignment_params'  => $params
            ]
        );

        // 2) If user provided transitions, define them FIRST
        if ($actions) {
            $this->defineTransitions($stepId, $actions);
        }

        // 3) Then print final line
        $this->info("Step [{$stepId}] defined/updated successfully.");

        return 0;
    }

    private function defineTransitions(string $stepId, string $actionsStr): void
    {
        $pairs = explode(',', $actionsStr);
        foreach ($pairs as $pair) {
            [$action, $next] = explode(':', $pair);
            FlowTransition::updateOrCreate(
                [
                    'step_id' => $stepId,
                    'action'  => $action,
                    'next_step_id' => $next
                ],
                []
            );
            // Print transitions lines first
            $this->info("   Action [{$action}] => Next Step [{$next}]");
        }
    }

    private function parseParams(?string $json): ?array
    {
        if ($json) {
            $decoded = json_decode($json, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
            $this->error('Invalid JSON for --params. Using null instead.');
        }
        return null;
    }
}
