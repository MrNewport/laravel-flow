<?php

use Illuminate\Support\Facades\{Artisan, DB, Schema};
use Illuminate\Database\Schema\Blueprint;
use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Models\FlowTransition;
use MrNewport\LaravelFlow\Tests\Support\FlowSubject;

it('migrates and rolls back on MySQL', function (string $type, int|string $id, string $columnType) {
    if (getenv('MYSQL_TEST_DATABASE') !== 'package_test') $this->markTestSkipped('Disposable MySQL runner only.');
    $originalConnection = config('database.default');
    config(['flow.morph_key_type' => $type, 'database.default' => 'mysql', 'database.connections.mysql' => [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
        'database' => 'package_test', 'username' => 'root', 'password' => getenv('MYSQL_TEST_PASSWORD'),
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
    ]]);
    DB::purge('mysql');
    Schema::create('users', fn (Blueprint $table) => $table->id());
    try {
        expect(Artisan::call('migrate', ['--database' => 'mysql', '--force' => true]))->toBe(0);
        expect(Schema::getColumnType('flow_instances', 'model_id', true))->toBe($columnType);
        FlowStep::create(['id' => 'review']);
        FlowTransition::create(['step_id' => 'review', 'action' => 'approve', 'next_step_id' => 'END']);
        $flow = FlowManager::startFlow('review', new FlowSubject(['id' => $id]));
        expect((string) $flow->fresh()->model_id)->toBe((string) $id);
        FlowManager::actionStep($flow->steps()->sole(), 'approve');
        expect($flow->fresh()->current_step_id)->toBe('END');
        expect(Artisan::call('migrate:rollback', ['--database' => 'mysql', '--force' => true]))->toBe(0);
        expect(DB::table('migrations')->count())->toBe(0);
    } finally {
        $migration = require __DIR__.'/../../src/database/migrations/2025_01_01_000000_create_flow_tables.php';
        $migration->down();
        Schema::dropIfExists('users');
        Schema::dropIfExists('migrations');
        config(['database.default' => $originalConnection]);
        app('migrator')->setConnection($originalConnection);
    }
})->with([
    'integer' => ['int', 123, 'bigint unsigned'],
    'UUID' => ['uuid', '2b2b8b5e-78fc-4a69-9b34-6d0a78a27403', 'char(36)'],
    'ULID' => ['ulid', '01K5HTMSN4QF8BTB00KZ91CWDP', 'char(26)'],
]);
