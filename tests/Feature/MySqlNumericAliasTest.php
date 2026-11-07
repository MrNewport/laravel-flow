<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\FlowInstance;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Tests\Support\FlowSubject;

it('keeps numeric morph aliases distinct from other MySQL subject types', function () {
    if (getenv('MYSQL_TEST_DATABASE') !== 'package_test') {
        $this->markTestSkipped('Disposable MySQL runner only.');
    }
    $original = config('database.default');
    config(['database.default' => 'mysql', 'database.connections.mysql' => [
        'driver' => 'mysql', 'host' => getenv('MYSQL_TEST_HOST') ?: '127.0.0.1', 'port' => getenv('MYSQL_TEST_PORT') ?: 3306,
        'unix_socket' => getenv('MYSQL_TEST_SOCKET') ?: '', 'database' => 'package_test',
        'username' => getenv('MYSQL_TEST_USERNAME') ?: 'root', 'password' => getenv('MYSQL_TEST_PASSWORD'),
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
    ]]);
    DB::purge('mysql');
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
    try {
        Artisan::call('migrate', ['--database' => 'mysql', '--force' => true]);
        Schema::create('flow_test_subjects', fn (Blueprint $table) => $table->unsignedBigInteger('id')->primary());
        $subject = FlowSubject::create(['id' => 17]);
        FlowStep::create(['id' => 'review']);
        Relation::enforceMorphMap([0 => FlowSubject::class, 'other' => FlowInstance::class], false);
        FlowInstance::create(['current_step_id' => 'review', 'model_type' => 'other', 'model_id' => 17]);
        $flow = FlowManager::startFlow('review', $subject);
        expect($flow->fresh()->model_type)->toBe('0')
            ->and($subject->fresh()->flowInstance()->sole()->is($flow))->toBeTrue()
            ->and($flow->fresh()->entity->is($subject))->toBeTrue();
    } finally {
        (require __DIR__.'/../../src/database/migrations/2026_11_06_000001_add_flow_lookup_indexes.php')->down();
        (require __DIR__.'/../../src/database/migrations/2025_01_01_000000_create_flow_tables.php')->down();
        Schema::dropIfExists('flow_test_subjects');
        Schema::dropIfExists('migrations');
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
        DB::disconnect('mysql');
        config(['database.default' => $original]);
        app('migrator')->setConnection($original);
    }
});
