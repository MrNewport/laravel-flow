<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Tests\Support\MockUser;

it('adds selective lookup indexes to saved data and rolls back only the indexes', function () {
    $migration = require __DIR__.'/../../src/database/migrations/2026_11_06_000001_add_flow_lookup_indexes.php';
    $migration->down();
    FlowStep::create(['id' => 'review']);
    $flow = FlowManager::startFlow('review', new MockUser(17));
    $savedRun = $flow->fresh()->getRawOriginal();
    $savedSteps = $flow->steps()->get()->map->getRawOriginal()->all();
    if (DB::connection()->getDriverName() === 'sqlite') {
        $before = DB::select('EXPLAIN QUERY PLAN SELECT id FROM flow_instances WHERE model_type = ? AND model_id = ? LIMIT 1', [MockUser::class, 17]);
        expect(implode(' ', array_column($before, 'detail')))->toContain('SCAN');
    }

    $migration->up();
    // A resumed migration can safely finish after a previous DDL operation succeeded.
    $migration->up();
    expect(Schema::hasIndex('flow_instances', ['model_type', 'model_id']))->toBeTrue()
        ->and(Schema::hasIndex('flow_instance_steps', ['flow_instance_id', 'finished_at', 'deleted_at', 'id']))->toBeTrue();
    if (DB::connection()->getDriverName() === 'sqlite') {
        $subject = DB::select('EXPLAIN QUERY PLAN SELECT id FROM flow_instances WHERE model_type = ? AND model_id = ? LIMIT 1', [MockUser::class, 17]);
        $pending = DB::select('EXPLAIN QUERY PLAN SELECT id FROM flow_instance_steps WHERE flow_instance_id = ? AND finished_at IS NULL AND deleted_at IS NULL ORDER BY id LIMIT 1', [$flow->getKey()]);
        expect(implode(' ', array_column($subject, 'detail')))->toContain('flow_instances_subject_idx')
            ->and(implode(' ', array_column($pending, 'detail')))->toContain('flow_instance_steps_pending_idx');
    }
    expect($flow->fresh()->getRawOriginal())->toBe($savedRun)
        ->and($flow->steps()->get()->map->getRawOriginal()->all())->toBe($savedSteps);
    $migration->down();
    expect(Schema::hasIndex('flow_instances', 'flow_instances_subject_idx'))->toBeFalse()
        ->and(Schema::hasIndex('flow_instance_steps', 'flow_instance_steps_pending_idx'))->toBeFalse()
        ->and($flow->fresh()->getRawOriginal())->toBe($savedRun)
        ->and($flow->steps()->get()->map->getRawOriginal()->all())->toBe($savedSteps);
});
