<?php

namespace MrNewport\LaravelFlow\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\FlowInstance;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Models\FlowTransition;
use MrNewport\LaravelFlow\Tests\Support\MockUser;
use MrNewport\LaravelFlow\Tests\TestCase;

class MySqlRegressionTest extends TestCase
{
    private function withMySql(callable $test): void
    {
        if (getenv('MYSQL_TEST_DATABASE') !== 'package_test') {
            $this->markTestSkipped('Disposable MySQL runner only.');
        }
        $original = config('database.default');
        config(['database.default' => 'mysql', 'database.connections.mysql' => [
            'driver' => 'mysql', 'host' => getenv('MYSQL_TEST_HOST') ?: '127.0.0.1',
            'port' => getenv('MYSQL_TEST_PORT') ?: 3306, 'unix_socket' => getenv('MYSQL_TEST_SOCKET') ?: '',
            'database' => 'package_test', 'username' => getenv('MYSQL_TEST_USERNAME') ?: 'root',
            'password' => getenv('MYSQL_TEST_PASSWORD') ?: '', 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ]]);
        DB::purge('mysql');
        $migration = require __DIR__.'/../../src/database/migrations/2025_01_01_000000_create_flow_tables.php';
        try {
            $migration->up();
            $test();
        } finally {
            DB::statement('DROP TABLE IF EXISTS flow_opaque_users');
            $migration->down();
            DB::disconnect('mysql');
            config(['database.default' => $original]);
            app('migrator')->setConnection($original);
        }
    }

    public function test_reserved_end_collation_aliases_are_rejected_without_mutation(): void
    {
        $this->withMySql(function (): void {
            foreach (['end', 'END '] as $id) {
                try {
                    FlowManager::startFlow($id, new MockUser(17));
                    $this->fail('A reserved terminal alias cannot be an initial step.');
                } catch (\InvalidArgumentException) {
                    $this->assertSame(0, FlowInstance::count());
                }
            }
            FlowStep::create(['id' => 'review']);
            FlowTransition::create(['step_id' => 'review', 'action' => 'approve', 'next_step_id' => 'end']);
            $flow = FlowManager::startFlow('review', new MockUser(17));
            $step = $flow->steps()->sole();
            try {
                FlowManager::actionStep($step, 'approve');
                $this->fail('Noncanonical END target must be rejected.');
            } catch (\InvalidArgumentException) {
                $this->assertNull($step->fresh()->finished_at);
                $this->assertSame(1, $flow->steps()->count());
            }
            FlowTransition::sole()->update(['next_step_id' => 'END']);
            $this->assertSame([null], FlowManager::actionStep($step, 'approve'));
            $this->assertNotNull($step->fresh()->finished_at);
        });
    }

    public function test_distinct_persisted_case_sensitive_user_ids_and_padded_keys_cannot_reassign(): void
    {
        $this->withMySql(function (): void {
            DB::statement('CREATE TABLE flow_opaque_users (id VARBINARY(255) NOT NULL PRIMARY KEY)');
            $users = new class extends Model
            {
                protected $table = 'flow_opaque_users';

                protected $guarded = [];

                protected $keyType = 'string';

                public $incrementing = false;

                public $timestamps = false;
            };
            foreach (['UserA', 'usera', '17', '17 '] as $id) {
                $users->newQuery()->create(['id' => $id]);
            }
            $this->assertSame(4, $users->newQuery()->count());
            foreach ([['single_user', 'UserA', 'usera'], ['multi_user', '17', '17 ']] as [$strategy, $assigned, $other]) {
                $id = $strategy.'-review';
                $params = $strategy === 'single_user' ? ['user_id' => $assigned] : ['user_ids' => [$assigned]];
                FlowStep::create(['id' => $id, 'assignment_strategy' => $strategy, 'assignment_params' => $params]);
                $step = FlowManager::startFlow($id, new MockUser(17))->steps()->sole();
                $this->assertFalse(FlowManager::reassignStep($step, $users->newQuery()->findOrFail($other), ['next']));
                $this->assertSame([$assigned], $step->assignees()->pluck('assignee_value')->all());
                $this->assertTrue(FlowManager::reassignStep($step, $users->newQuery()->findOrFail($assigned), ['next']));
            }
        });
    }

    public function test_saved_transition_tuples_support_targeted_instance_update_refresh_and_delete(): void
    {
        $this->withMySql(function (): void {
            DB::table('flow_transitions')->insert([
                ['step_id' => 'review', 'action' => 'approve', 'next_step_id' => 'END'],
                ['step_id' => 'review', 'action' => 'approve', 'next_step_id' => 'other'],
            ]);
            $transition = FlowTransition::where('next_step_id', 'END')->sole();
            $this->assertTrue($transition->update(['action' => 'reject']));
            $this->assertSame('reject', $transition->refresh()->action);
            $this->assertTrue($transition->delete());
            $this->assertSame('other', FlowTransition::sole()->next_step_id);
        });
    }

    public function test_history_date_upgrade_preserves_saved_rows_and_supports_wide_range_without_destructive_down(): void
    {
        $this->withMySql(function (): void {
            $id = DB::table('flow_instance_steps')->insertGetId(['flow_instance_id' => 1, 'step_id' => 'review', 'started_at' => '2024-01-02 16:00:00', 'finished_at' => '2024-01-03 16:00:00']);
            $upgrade = require __DIR__.'/../../src/database/migrations/2026_11_06_000002_widen_flow_history_dates.php';
            $upgrade->up();
            $this->assertSame('2024-01-03 16:00:00', DB::table('flow_instance_steps')->where('id', $id)->value('finished_at'));
            DB::table('flow_instance_steps')->where('id', $id)->update(['started_at' => '1969-01-01 00:00:00', 'finished_at' => '2039-01-02 00:00:00']);
            $upgrade->down();
            $this->assertSame('2039-01-02 00:00:00', DB::table('flow_instance_steps')->where('id', $id)->value('finished_at'));
            $columns = DB::select("SHOW COLUMNS FROM flow_instance_steps WHERE Field IN ('started_at', 'finished_at')");
            foreach ($columns as $column) {
                $this->assertSame('datetime', $column->Type);
            }
        });
    }

    public function test_mysql_unsigned_subject_key_retains_exact_identity(): void
    {
        $this->withMySql(function (): void {
            FlowStep::create(['id' => 'review']);
            $key = '18446744073709551615';
            $subject = new class($key)
            {
                public function __construct(private string $key) {}

                public function getKey(): string
                {
                    return $this->key;
                }
            };
            $this->assertSame($key, (string) FlowManager::startFlow('review', $subject)->fresh()->model_id);
        });
    }
}
