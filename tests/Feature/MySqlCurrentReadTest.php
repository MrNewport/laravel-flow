<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Tests\Support\MockUser;

it('uses current definition reads after an older MySQL repeatable-read snapshot', function () {
    if (getenv('MYSQL_TEST_DATABASE') !== 'package_test') {
        $this->markTestSkipped('Disposable MySQL runner only.');
    }
    $originalConnection = config('database.default');
    $configuration = [
        'driver' => 'mysql', 'host' => getenv('MYSQL_TEST_HOST') ?: '127.0.0.1', 'port' => getenv('MYSQL_TEST_PORT') ?: 3306,
        'unix_socket' => getenv('MYSQL_TEST_SOCKET') ?: '',
        'database' => 'package_test', 'username' => getenv('MYSQL_TEST_USERNAME') ?: 'root', 'password' => getenv('MYSQL_TEST_PASSWORD'),
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
    ];
    config(['database.default' => 'mysql', 'database.connections.mysql' => $configuration, 'database.connections.flow_writer' => $configuration]);
    DB::purge('mysql');
    DB::purge('flow_writer');

    try {
        expect(Artisan::call('migrate', ['--database' => 'mysql', '--force' => true]))->toBe(0);
        $connection = DB::connection('mysql');
        $writer = DB::connection('flow_writer');
        $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        $connection->beginTransaction();
        expect($connection->table('flow_steps')->where('id', 'newly-published')->exists())->toBeFalse();
        $writer->table('flow_steps')->insert(['id' => 'newly-published', 'name' => 'New definition']);
        $flow = FlowManager::startFlow('newly-published', new MockUser(17));
        expect($flow->current_step_id)->toBe('newly-published');
        $connection->commit();

        $connection->beginTransaction();
        expect($connection->table('flow_transitions')->where('step_id', 'newly-published')->exists())->toBeFalse();
        $writer->table('flow_steps')->insert(['id' => 'new-successor', 'name' => 'New successor']);
        $writer->table('flow_transitions')->insert(['step_id' => 'newly-published', 'action' => 'advance', 'next_step_id' => 'new-successor']);
        $created = FlowManager::actionStep($flow->steps()->sole(), 'advance');
        expect($created)->toHaveCount(1)->and($created[0]->step_id)->toBe('new-successor');
        $connection->commit();

        FlowStep::create(['id' => 'assigned', 'assignment_strategy' => 'single_user', 'assignment_params' => ['user_id' => 17]]);
        $assigned = FlowManager::startFlow('assigned', new MockUser(17))->steps()->sole();
        $connection->beginTransaction();
        expect(FlowStep::findOrFail('assigned')->assignment_strategy)->toBe('single_user');
        $writer->table('flow_steps')->where('id', 'assigned')->update(['assignment_strategy' => 'multi_user', 'assignment_params' => json_encode(['user_ids' => [17]], JSON_THROW_ON_ERROR)]);
        expect(FlowManager::reassignStep($assigned, new MockUser(17), [23, 24]))->toBeTrue()
            ->and($assigned->assignees()->pluck('assignee_value')->all())->toBe(['23', '24']);
        $connection->commit();

        $connection->beginTransaction();
        expect($assigned->assignees()->where('assignee_value', '23')->exists())->toBeTrue();
        $writer->table('flow_step_assignees')->where('flow_instance_step_id', $assigned->getKey())->delete();
        $writer->table('flow_step_assignees')->insert(['flow_instance_step_id' => $assigned->getKey(), 'assignee_type' => 'user', 'assignee_value' => '99']);
        expect(FlowManager::reassignStep($assigned, new MockUser(23), [24]))->toBeFalse();
        $connection->commit();
        expect($assigned->assignees()->pluck('assignee_value')->all())->toBe(['99']);

        $connection->beginTransaction();
        $connection->table('flow_instances')->count();
        $instanceId = $writer->table('flow_instances')->insertGetId(['current_step_id' => 'newly-published', 'model_type' => MockUser::class, 'model_id' => 29]);
        $stepId = $writer->table('flow_instance_steps')->insertGetId(['flow_instance_id' => $instanceId, 'step_id' => 'newly-published']);
        $unseen = (new FlowInstanceStep)->forceFill(['id' => $stepId]);
        expect(FlowManager::actionStep($unseen, 'advance'))->toHaveCount(1);
        $connection->commit();
    } finally {
        while (DB::connection('mysql')->transactionLevel() > 0) {
            DB::connection('mysql')->rollBack();
        }
        $migration = require __DIR__.'/../../src/database/migrations/2025_01_01_000000_create_flow_tables.php';
        $migration->down();
        Schema::dropIfExists('migrations');
        DB::disconnect('flow_writer');
        DB::disconnect('mysql');
        config(['database.default' => $originalConnection]);
        app('migrator')->setConnection($originalConnection);
    }
});
