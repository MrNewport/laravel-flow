<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Models\FlowTransition;
use MrNewport\LaravelFlow\Tests\Support\FlowSubject;

it('migrates and resolves a subject using the configured key type', function (string $type, int|string $id, string $columnType) {
    $migration = require __DIR__.'/../../src/database/migrations/2025_01_01_000000_create_flow_tables.php';
    $migration->down();
    config(['flow.morph_key_type' => $type]);
    $migration->up();
    Schema::create('flow_test_subjects', function (Blueprint $table) use ($type) {
        $column = match ($type) {
            'uuid' => $table->uuid('id'),
            'ulid' => $table->ulid('id'),
            default => $table->unsignedBigInteger('id'),
        };
        $column->primary();
    });

    $subject = FlowSubject::create(['id' => $id]);
    FlowStep::create(['id' => 'review']);
    FlowTransition::create(['step_id' => 'review', 'action' => 'approve', 'next_step_id' => 'END']);
    $flow = FlowManager::startFlow('review', $subject);

    expect(Schema::getColumnType('flow_instances', 'model_id'))->toBe($columnType)
        ->and((string) $flow->fresh()->model_id)->toBe((string) $id)
        ->and($flow->fresh()->entity->is($subject))->toBeTrue()
        ->and($subject->fresh()->flowInstance->is($flow))->toBeTrue();

    $subject->flowAction('approve');

    expect($flow->fresh()->current_step_id)->toBe('END')
        ->and($flow->steps()->sole()->action_taken)->toBe('approve');
})->with([
    'integer' => ['int', 123, 'integer'],
    'UUID' => ['uuid', '2b2b8b5e-78fc-4a69-9b34-6d0a78a27403', 'varchar'],
    'ULID' => ['ulid', '01K5HTMSN4QF8BTB00KZ91CWDP', 'varchar'],
]);

it('rejects invalid key configuration before creating any package tables', function () {
    $migration = require __DIR__.'/../../src/database/migrations/2025_01_01_000000_create_flow_tables.php';
    $migration->down();
    config(['flow.morph_key_type' => 'unsupported']);

    expect(fn () => $migration->up())->toThrow(InvalidArgumentException::class)
        ->and(Schema::hasTable('flow_steps'))->toBeFalse()
        ->and(Schema::hasTable('flow_instances'))->toBeFalse();
});

it('does not convert existing tables when the configuration changes', function () {
    expect(Schema::getColumnType('flow_instances', 'model_id'))->toBe('integer');
    config(['flow.morph_key_type' => 'uuid']);

    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0)
        ->and(Schema::getColumnType('flow_instances', 'model_id'))->toBe('integer');
});
