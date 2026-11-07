<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Tests\Support\MockUser;

it('uses additive subject and pending indexes on existing MySQL data', function () {
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
    $index = require __DIR__.'/../../src/database/migrations/2026_11_06_000001_add_flow_lookup_indexes.php';
    try {
        Artisan::call('migrate', ['--database' => 'mysql', '--force' => true]);
        $index->down();
        FlowStep::create(['id' => 'review']);
        $flow = FlowManager::startFlow('review', new MockUser(17));
        $savedRun = $flow->fresh()->getRawOriginal();
        $savedSteps = $flow->steps()->get()->map->getRawOriginal()->all();
        $runs = [];
        $steps = [];
        for ($number = 1; $number <= 2000; $number++) {
            $runs[] = ['current_step_id' => 'END', 'model_type' => 'index-fixture', 'model_id' => $number];
            $steps[] = ['flow_instance_id' => $flow->getKey(), 'step_id' => 'review', 'finished_at' => '2026-01-01 00:00:00'];
        }
        foreach (array_chunk($runs, 200) as $chunk) {
            DB::table('flow_instances')->insert($chunk);
        }
        foreach (array_chunk($steps, 200) as $chunk) {
            DB::table('flow_instance_steps')->insert($chunk);
        }
        $subjectSql = 'EXPLAIN SELECT id FROM flow_instances WHERE model_type = ? AND model_id = ? LIMIT 1';
        $pendingSql = 'EXPLAIN SELECT id FROM flow_instance_steps WHERE flow_instance_id = ? AND finished_at IS NULL AND deleted_at IS NULL ORDER BY id LIMIT 1';
        expect(DB::select($subjectSql, [MockUser::class, 17])[0]->type)->toBe('ALL');
        $index->up();
        DB::statement('ANALYZE TABLE flow_instances, flow_instance_steps');
        expect(DB::select($subjectSql, [MockUser::class, 17])[0]->key)->toBe('flow_instances_subject_idx')
            ->and(DB::select($pendingSql, [$flow->getKey()])[0]->key)->toBe('flow_instance_steps_pending_idx')
            ->and($flow->fresh()->getRawOriginal())->toBe($savedRun)
            ->and($flow->steps()->whereKey($savedSteps[0]['id'])->sole()->getRawOriginal())->toBe($savedSteps[0]);
        $index->down();
        expect(Schema::hasIndex('flow_instances', 'flow_instances_subject_idx'))->toBeFalse()
            ->and(Schema::hasIndex('flow_instance_steps', 'flow_instance_steps_pending_idx'))->toBeFalse()
            ->and($flow->fresh()->getRawOriginal())->toBe($savedRun)
            ->and(DB::table('flow_instance_steps')->count())->toBe(2001);
    } finally {
        $index->down();
        (require __DIR__.'/../../src/database/migrations/2025_01_01_000000_create_flow_tables.php')->down();
        Schema::dropIfExists('migrations');
        DB::disconnect('mysql');
        config(['database.default' => $original]);
        app('migrator')->setConnection($original);
    }
});
