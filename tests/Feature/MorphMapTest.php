<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\FlowInstance;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Support\MorphMapMigration;
use MrNewport\LaravelFlow\Tests\Support\FlowSubject;
use MrNewport\LaravelFlow\Tests\Support\MockUser;

beforeEach(function () {
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
});

afterEach(function () {
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
});

function createMappedFlowSubject(string $type = 'int', int|string $id = 17): FlowSubject
{
    $migration = require __DIR__.'/../../src/database/migrations/2025_01_01_000000_create_flow_tables.php';
    $migration->down();
    config(['flow.morph_key_type' => $type]);
    $migration->up();
    Schema::create('flow_test_subjects', function (Blueprint $table) use ($type) {
        match ($type) {
            'uuid' => $table->uuid('id')->primary(),
            'ulid' => $table->ulid('id')->primary(),
            default => $table->unsignedBigInteger('id')->primary(),
        };
    });
    FlowStep::create(['id' => 'review']);

    return FlowSubject::create(['id' => $id]);
}

it('stores the registered alias and resolves both directions under an enforced map', function (string $type, int|string $id) {
    $subject = createMappedFlowSubject($type, $id);
    Relation::enforceMorphMap(['subject' => FlowSubject::class], false);
    $flow = FlowManager::startFlow('review', $subject);

    expect($flow->fresh()->model_type)->toBe('subject')
        ->and($flow->fresh()->entity->is($subject))->toBeTrue()
        ->and($subject->fresh()->flowInstance->is($flow))->toBeTrue()
        ->and($subject->fresh()->load('flowInstance')->flowInstance->is($flow))->toBeTrue()
        ->and((string) $flow->fresh()->model_id)->toBe((string) $id);
})->with([
    'integer' => ['int', 17],
    'UUID' => ['uuid', '2b2b8b5e-78fc-4a69-9b34-6d0a78a27403'],
    'ULID' => ['ulid', '01K5HTMSN4QF8BTB00KZ91CWDP'],
]);

it('retains historical FQCN rows when a non-enforced map is introduced', function () {
    $subject = createMappedFlowSubject();
    $flow = FlowManager::startFlow('review', $subject);
    expect($flow->model_type)->toBe(FlowSubject::class);
    Relation::morphMap(['subject' => FlowSubject::class], false);

    expect($subject->fresh()->flowInstance->is($flow))->toBeTrue()
        ->and($subject->fresh()->load('flowInstance')->flowInstance->is($flow))->toBeTrue()
        ->and($flow->fresh()->entity->is($subject))->toBeTrue()
        ->and(FlowInstance::where('model_type', FlowSubject::class)->count())->toBe(1);
});

it('finds existing alias rows without mixing another subject type with the same id', function () {
    $subject = createMappedFlowSubject();
    Relation::morphMap(['subject' => FlowSubject::class], false);
    FlowInstance::create(['current_step_id' => 'review', 'model_type' => 'unrelated', 'model_id' => $subject->getKey()]);
    $flow = FlowInstance::create(['current_step_id' => 'review', 'model_type' => 'subject', 'model_id' => $subject->getKey()]);

    expect($subject->flowInstance()->sole()->is($flow))->toBeTrue()
        ->and($flow->fresh()->entity->is($subject))->toBeTrue();
});

it('binds numeric morph aliases as strings and excludes other types with the same key', function () {
    $subject = createMappedFlowSubject();
    Relation::enforceMorphMap([0 => FlowSubject::class, 'other' => FlowInstance::class], false);
    FlowInstance::create(['current_step_id' => 'review', 'model_type' => 'other', 'model_id' => $subject->getKey()]);
    $flow = FlowManager::startFlow('review', $subject);
    expect($subject->flowInstance()->getQuery()->getBindings())->toContain('0')
        ->and($subject->fresh()->flowInstance()->sole()->is($flow))->toBeTrue();
});

it('preserves generic getKey subjects without an Eloquent morph method', function () {
    FlowStep::create(['id' => 'review']);
    $flow = FlowManager::startFlow('review', new MockUser(99));
    expect($flow->model_type)->toBe(MockUser::class)->and($flow->model_id)->toBe(99);
});

it('previews and explicitly converts old types without changing keys history or timestamps', function (string $type, int|string $id) {
    $subject = createMappedFlowSubject($type, $id);
    $flow = FlowManager::startFlow('review', $subject);
    Relation::morphMap(['subject' => FlowSubject::class], false);
    $migration = new MorphMapMigration;
    $original = $flow->fresh()->getRawOriginal();
    $steps = $flow->steps()->get()->map->getRawOriginal()->all();
    $plan = $migration->preview();
    expect($plan['total'])->toBe(1)->and($plan['conversions'])->toBe([['from' => FlowSubject::class, 'to' => 'subject', 'count' => 1]])
        ->and($flow->fresh()->model_type)->toBe(FlowSubject::class);
    expect($migration->apply($plan['fingerprint'])['converted'])->toBe(1);
    Relation::requireMorphMap();
    $expected = $original;
    $expected['model_type'] = 'subject';
    expect($flow->fresh()->getRawOriginal())->toBe($expected)
        ->and($flow->steps()->get()->map->getRawOriginal()->all())->toBe($steps)
        ->and($flow->fresh()->entity->is($subject))->toBeTrue()
        ->and($subject->fresh()->flowInstance->is($flow))->toBeTrue();
    $again = $migration->preview();
    expect($again['conversions'])->toBe([])->and($migration->apply($again['fingerprint'])['converted'])->toBe(0);
})->with([
    'integer' => ['int', 17],
    'UUID' => ['uuid', '2b2b8b5e-78fc-4a69-9b34-6d0a78a27403'],
    'ULID' => ['ulid', '01K5HTMSN4QF8BTB00KZ91CWDP'],
]);

it('refuses to convert after the reviewed map or instance set changes', function () {
    $subject = createMappedFlowSubject();
    $flow = FlowManager::startFlow('review', $subject);
    Relation::morphMap(['subject' => FlowSubject::class], false);
    $migration = new MorphMapMigration;
    $oldPlan = $migration->preview();
    Relation::morphMap(['renamed' => FlowSubject::class], false);
    expect(fn () => $migration->apply($oldPlan['fingerprint']))->toThrow(LogicException::class)
        ->and($flow->fresh()->model_type)->toBe(FlowSubject::class);
    $newPlan = $migration->preview();
    FlowManager::startFlow('review', $subject);
    expect(fn () => $migration->apply($newPlan['fingerprint']))->toThrow(LogicException::class)
        ->and($flow->fresh()->model_type)->toBe(FlowSubject::class);
});

it('rejects ambiguous mappings before any conversion', function () {
    $subject = createMappedFlowSubject();
    $flow = FlowManager::startFlow('review', $subject);
    Relation::morphMap(['subject' => FlowSubject::class, 'another' => FlowSubject::class], false);
    expect(fn () => (new MorphMapMigration)->preview())->toThrow(LogicException::class)
        ->and($flow->fresh()->model_type)->toBe(FlowSubject::class);
});

it('rejects nonportable aliases before a database collation can fold them', function (string $alias) {
    $subject = createMappedFlowSubject();
    $flow = FlowManager::startFlow('review', $subject);
    Relation::morphMap([$alias => FlowSubject::class], false);
    expect(fn () => (new MorphMapMigration)->preview())->toThrow(InvalidArgumentException::class)
        ->and($flow->fresh()->model_type)->toBe(FlowSubject::class);
})->with(['résumé', 'review subject', '\\subject', '123']);

it('reports unknown subjects and refuses the whole conversion', function () {
    $subject = createMappedFlowSubject();
    $flow = FlowManager::startFlow('review', $subject);
    Relation::morphMap(['subject' => FlowSubject::class], false);
    FlowManager::startFlow('review', new MockUser(99));
    $migration = new MorphMapMigration;
    $plan = $migration->preview();
    expect($plan['unresolved'])->toBe([['type' => MockUser::class, 'count' => 1]])
        ->and(fn () => $migration->apply($plan['fingerprint']))->toThrow(LogicException::class)
        ->and($flow->fresh()->model_type)->toBe(FlowSubject::class);
});

it('rolls back the entire conversion if a database write fails', function () {
    $subject = createMappedFlowSubject();
    $flow = FlowManager::startFlow('review', $subject);
    Relation::morphMap(['subject' => FlowSubject::class], false);
    $migration = new MorphMapMigration;
    $plan = $migration->preview();
    $trigger = DB::connection()->getDriverName() === 'mysql'
        ? "CREATE TRIGGER block_morph_conversion BEFORE UPDATE ON flow_instances FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'blocked'"
        : "CREATE TRIGGER block_morph_conversion BEFORE UPDATE ON flow_instances BEGIN SELECT RAISE(ABORT, 'blocked'); END";
    DB::statement($trigger);
    expect(fn () => $migration->apply($plan['fingerprint']))->toThrow(QueryException::class)
        ->and($flow->fresh()->model_type)->toBe(FlowSubject::class);
});

it('registers a preview-only command and requires a reviewed hash for writes', function () {
    $subject = createMappedFlowSubject();
    $flow = FlowManager::startFlow('review', $subject);
    Relation::morphMap(['subject' => FlowSubject::class], false);
    expect(Artisan::call('flow:morph-map'))->toBe(0);
    $plan = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($flow->fresh()->model_type)->toBe(FlowSubject::class)
        ->and(Artisan::call('flow:morph-map', ['--apply' => true]))->toBe(1)
        ->and(Artisan::call('flow:morph-map', ['--apply' => true, '--expect' => str_repeat('0', 64)]))->toBe(1)
        ->and($flow->fresh()->model_type)->toBe(FlowSubject::class)
        ->and(Artisan::call('flow:morph-map', ['--apply' => true, '--expect' => $plan['fingerprint']]))->toBe(0)
        ->and($flow->fresh()->model_type)->toBe('subject');
});
