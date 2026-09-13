<?php

use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\{FlowStep, FlowTransition, FlowInstance, FlowInstanceStep};

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
