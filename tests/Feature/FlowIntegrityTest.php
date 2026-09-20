<?php

use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\{FlowStep, FlowTransition, FlowInstance, FlowInstanceStep};
use Illuminate\Support\Facades\Event;
use MrNewport\LaravelFlow\Events\FlowActionEvent;

it('rolls back a missing start step', function () {
    $entity = new class { public function getKey() { return 1; } };
    expect(fn () => FlowManager::startFlow('missing', $entity))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(FlowInstance::count())->toBe(0);
});

it('rolls back a failed transition and rejects duplicate completion', function () {
    FlowStep::create(['id' => 'start']);
    FlowTransition::create(['step_id' => 'start', 'action' => 'go', 'next_step_id' => 'missing']);
    $entity = new class { public function getKey() { return 1; } };
    $flow = FlowManager::startFlow('start', $entity);
    $step = $flow->steps()->first();
    expect(fn () => FlowManager::actionStep($step, 'go'))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect($step->fresh()->finished_at)->toBeNull()->and(FlowInstanceStep::count())->toBe(1);
    FlowStep::create(['id' => 'missing']);
    FlowManager::actionStep($step, 'go');
    expect(fn () => FlowManager::actionStep($step, 'go'))->toThrow(LogicException::class);
    expect(FlowInstanceStep::count())->toBe(2);
});

it('registers actual config and notification view paths', function () {
    expect(view()->exists('laravel-flow::notifications.flow_base'))->toBeTrue();
    $paths = \Illuminate\Support\ServiceProvider::pathsToPublish(\MrNewport\LaravelFlow\Providers\FlowServiceProvider::class, 'flow-config');
    foreach ($paths as $source => $destination) {
        expect(is_file($source))->toBeTrue();
    }
    expect($paths)->not->toBeEmpty();
});

it('preserves a pending step and emits no event for an undefined action', function () {
    Event::fake([FlowActionEvent::class]);
    FlowStep::create(['id' => 'review']);
    FlowTransition::create(['step_id' => 'review', 'action' => 'approve', 'next_step_id' => 'END']);
    $flow = FlowManager::startFlow('review', new \MrNewport\LaravelFlow\Tests\Support\MockUser(12));
    $step = $flow->steps()->sole();

    expect(fn () => FlowManager::actionStep($step, 'typo'))->toThrow(InvalidArgumentException::class);

    expect($step->fresh()->finished_at)->toBeNull()
        ->and($step->fresh()->action_taken)->toBeNull()
        ->and($flow->fresh()->current_step_id)->toBe('review')
        ->and($flow->steps()->count())->toBe(1);
    Event::assertNotDispatched(FlowActionEvent::class);

    expect(FlowManager::actionStep($step, 'approve'))->toBe([null])
        ->and($flow->fresh()->current_step_id)->toBe('END');
    Event::assertDispatchedTimes(FlowActionEvent::class, 1);
});

it('rolls back a flow whose initial assignment strategy is unsupported', function () {
    FlowStep::create(['id' => 'review', 'assignment_strategy' => 'custom_review']);

    expect(fn () => FlowManager::startFlow('review', new \MrNewport\LaravelFlow\Tests\Support\MockUser(12)))
        ->toThrow(InvalidArgumentException::class, 'Unsupported flow assignment strategy: custom_review');

    expect(FlowInstance::count())->toBe(0)->and(FlowInstanceStep::count())->toBe(0);
});

it('rolls back completion when the next assignment strategy is unsupported', function () {
    Event::fake([FlowActionEvent::class]);
    FlowStep::create(['id' => 'start']);
    FlowStep::create(['id' => 'review', 'assignment_strategy' => 'custom_review']);
    FlowTransition::create(['step_id' => 'start', 'action' => 'submit', 'next_step_id' => 'review']);
    $flow = FlowManager::startFlow('start', new \MrNewport\LaravelFlow\Tests\Support\MockUser(12));
    $step = $flow->steps()->sole();

    expect(fn () => FlowManager::actionStep($step, 'submit'))->toThrow(InvalidArgumentException::class);

    expect($step->fresh()->finished_at)->toBeNull()
        ->and($flow->fresh()->current_step_id)->toBe('start')
        ->and($flow->steps()->count())->toBe(1);
    Event::assertNotDispatched(FlowActionEvent::class);
});
