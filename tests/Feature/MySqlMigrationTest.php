<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Models\FlowTransition;
use MrNewport\LaravelFlow\Support\MorphMapMigration;
use MrNewport\LaravelFlow\Tests\Support\FlowSubject;

it('migrates and rolls back on MySQL', function (string $type, int|string $id, string $columnType) {
    if (getenv('MYSQL_TEST_DATABASE') !== 'package_test') {
        $this->markTestSkipped('Disposable MySQL runner only.');
    }
    $originalConnection = config('database.default');
    config(['flow.morph_key_type' => $type, 'database.default' => 'mysql', 'database.connections.mysql' => [
        'driver' => 'mysql', 'host' => getenv('MYSQL_TEST_HOST') ?: '127.0.0.1', 'port' => getenv('MYSQL_TEST_PORT') ?: 3306,
        'unix_socket' => getenv('MYSQL_TEST_SOCKET') ?: '',
        'database' => 'package_test', 'username' => getenv('MYSQL_TEST_USERNAME') ?: 'root', 'password' => getenv('MYSQL_TEST_PASSWORD'),
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
    ]]);
    DB::purge('mysql');
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
    Schema::create('users', fn (Blueprint $table) => $table->id());
    try {
        expect(Artisan::call('migrate', ['--database' => 'mysql', '--force' => true]))->toBe(0);
        expect(Schema::getColumnType('flow_instances', 'model_id', true))->toBe($columnType);
        FlowStep::create(['id' => 'review']);
        FlowTransition::create(['step_id' => 'review', 'action' => 'approve', 'next_step_id' => 'END']);
        Schema::create('flow_test_subjects', function (Blueprint $table) use ($type) {
            match ($type) {
                'uuid' => $table->uuid('id')->primary(),
                'ulid' => $table->ulid('id')->primary(),
                default => $table->unsignedBigInteger('id')->primary(),
            };
        });
        $subject = FlowSubject::create(['id' => $id]);
        $flow = FlowManager::startFlow('review', $subject);
        expect((string) $flow->fresh()->model_id)->toBe((string) $id)
            ->and($flow->fresh()->model_type)->toBe(FlowSubject::class);
        Relation::morphMap(['subject' => FlowSubject::class], false);
        $original = $flow->fresh()->getRawOriginal();
        $history = $flow->steps()->get()->map->getRawOriginal()->all();
        $conversion = new MorphMapMigration;
        $plan = $conversion->preview();
        expect($conversion->apply($plan['fingerprint'])['converted'])->toBe(1);
        Relation::requireMorphMap();
        $expected = $original;
        $expected['model_type'] = 'subject';
        expect($flow->fresh()->getRawOriginal())->toBe($expected)
            ->and($flow->steps()->get()->map->getRawOriginal()->all())->toBe($history)
            ->and($flow->fresh()->entity->is($subject))->toBeTrue()
            ->and($subject->fresh()->flowInstance->is($flow))->toBeTrue();
        $aliasFlow = FlowManager::startFlow('review', $subject);
        expect($aliasFlow->fresh()->model_type)->toBe('subject')
            ->and($aliasFlow->fresh()->entity->is($subject))->toBeTrue();
        FlowManager::actionStep($flow->steps()->sole(), 'approve');
        expect($flow->fresh()->current_step_id)->toBe('END');
        expect(Artisan::call('migrate:rollback', ['--database' => 'mysql', '--force' => true]))->toBe(0);
        expect(DB::table('migrations')->count())->toBe(0);
    } finally {
        $migration = require __DIR__.'/../../src/database/migrations/2025_01_01_000000_create_flow_tables.php';
        $migration->down();
        Schema::dropIfExists('flow_test_subjects');
        Schema::dropIfExists('users');
        Schema::dropIfExists('migrations');
        config(['database.default' => $originalConnection]);
        app('migrator')->setConnection($originalConnection);
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
    }
})->with([
    'integer' => ['int', 123, 'bigint unsigned'],
    'UUID' => ['uuid', '2b2b8b5e-78fc-4a69-9b34-6d0a78a27403', 'char(36)'],
    'ULID' => ['ulid', '01K5HTMSN4QF8BTB00KZ91CWDP', 'char(26)'],
]);
