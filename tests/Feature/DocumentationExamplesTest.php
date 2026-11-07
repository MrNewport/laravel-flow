<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Tests\Support\FlowSubject;

it('executes the documented native parallel review lifecycle without confusing END with global completion', function () {
    Schema::create('flow_test_subjects', fn (Blueprint $table) => $table->id());
    $subject = FlowSubject::create(['id' => 17]);
    $seed = require __DIR__.'/../../examples/review.php';
    $seed();
    $seed(); // reviewed definition seeding is repeatable
    $instance = $subject->startFlow('parallel.submit.v1');
    $branches = FlowManager::actionStep($instance->steps()->sole(), 'submit');
    expect($branches)->toHaveCount(2);
    $legal = $instance->steps()->where('step_id', 'parallel.legal.v1')->whereNull('finished_at')->sole();
    expect(FlowManager::actionStep($legal, 'approve'))->toBe([null])
        ->and($instance->fresh()->current_step_id)->toBe('END')
        ->and($instance->steps()->whereNull('finished_at')->pluck('step_id')->all())->toBe(['parallel.finance.v1']);
    $finance = $subject->currentFlowStep();
    expect($finance->step_id)->toBe('parallel.finance.v1');
    FlowManager::actionStep($finance, 'approve');
    expect($instance->steps()->whereNull('finished_at')->exists())->toBeFalse()
        ->and($subject->currentFlowStep())->toBeNull()
        ->and($instance->steps()->whereNotNull('finished_at')->count())->toBe(3);
});
