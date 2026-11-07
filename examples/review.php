<?php

use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Models\FlowTransition;

// Invoke in a booted Laravel application with Flow migrations applied.
return static function (): void {
    foreach (['parallel.submit.v1', 'parallel.legal.v1', 'parallel.finance.v1'] as $id) {
        FlowStep::firstOrCreate(['id' => $id]);
    }
    foreach (['parallel.legal.v1', 'parallel.finance.v1'] as $target) {
        FlowTransition::firstOrCreate([
            'step_id' => 'parallel.submit.v1', 'action' => 'submit', 'next_step_id' => $target,
        ]);
        FlowTransition::firstOrCreate(['step_id' => $target, 'action' => 'approve', 'next_step_id' => 'END']);
    }
};
