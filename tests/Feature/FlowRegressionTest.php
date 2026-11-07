<?php

namespace MrNewport\LaravelFlow\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use MrNewport\LaravelFlow\Assignments\AssignmentValues;
use MrNewport\LaravelFlow\Assignments\MultiUserStrategy;
use MrNewport\LaravelFlow\Events\FlowActionEvent;
use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\FlowInstance;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Models\FlowStepAssignee;
use MrNewport\LaravelFlow\Models\FlowTransition;
use MrNewport\LaravelFlow\Support\MorphMapMigration;
use MrNewport\LaravelFlow\Tests\Support\FlowSubject;
use MrNewport\LaravelFlow\Tests\Support\MockUser;
use MrNewport\LaravelFlow\Tests\TestCase;

class FlowRegressionTest extends TestCase
{
    private function workflow(): FlowInstanceStep
    {
        FlowStep::create(['id' => 'review', 'assignment_strategy' => 'multi_user', 'assignment_params' => ['user_ids' => [17]]]);
        FlowStep::create(['id' => 'publish']);
        FlowTransition::create(['step_id' => 'review', 'action' => 'approve', 'next_step_id' => 'publish']);

        return FlowManager::startFlow('review', new MockUser(17))->steps()->sole();
    }

    private function refused(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a cancelled persistence operation to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cancelled', $exception->getMessage());
        }
    }

    public function test_cancelled_completion_cannot_create_successors_or_events(): void
    {
        $step = $this->workflow();
        Event::fake([FlowActionEvent::class]);
        FlowInstanceStep::updating(fn () => false);
        $this->refused(fn () => FlowManager::actionStep($step, 'approve'));
        $this->refused(fn () => FlowManager::actionStep($step, 'approve'));
        $this->assertNull($step->fresh()->finished_at);
        $this->assertSame('review', $step->flowInstance->fresh()->current_step_id);
        $this->assertSame(1, FlowInstanceStep::count());
        Event::assertNotDispatched(FlowActionEvent::class);
    }

    public function test_cancelled_instance_pointer_rolls_back_completion_successor_and_event(): void
    {
        $step = $this->workflow();
        Event::fake([FlowActionEvent::class]);
        FlowInstance::updating(fn () => false);
        $this->refused(fn () => FlowManager::actionStep($step, 'approve'));
        $this->assertNull($step->fresh()->finished_at);
        $this->assertSame(1, FlowInstanceStep::count());
        Event::assertNotDispatched(FlowActionEvent::class);
    }

    public function test_cancelled_creation_of_instance_or_initial_step_rolls_back_start(): void
    {
        FlowStep::create(['id' => 'review']);
        FlowInstance::creating(fn () => false);
        $this->refused(fn () => FlowManager::startFlow('review', new MockUser(17)));
        $this->assertSame(0, FlowInstance::count());
        $this->assertSame(0, FlowInstanceStep::count());
        FlowInstance::flushEventListeners();
        FlowInstanceStep::creating(fn () => false);
        $this->refused(fn () => FlowManager::startFlow('review', new MockUser(17)));
        $this->assertSame(0, FlowInstance::count());
    }

    public function test_cancelled_successor_creation_rolls_back_completion(): void
    {
        $step = $this->workflow();
        FlowInstanceStep::creating(fn () => false);
        $this->refused(fn () => FlowManager::actionStep($step, 'approve'));
        $this->assertNull($step->fresh()->finished_at);
        $this->assertSame(1, FlowInstanceStep::count());
    }

    public function test_assignment_veto_preserves_old_assignments_on_manager_and_direct_strategy_calls(): void
    {
        $step = $this->workflow();
        FlowStepAssignee::creating(fn ($candidate) => $candidate->assignee_value === 'fail' ? false : null);
        $this->refused(fn () => FlowManager::reassignStep($step, new MockUser(17), ['23', 'fail']));
        $this->assertSame(['17'], $step->assignees()->pluck('assignee_value')->all());
        $this->refused(fn () => (new MultiUserStrategy)->reassign($step, ['23', 'fail']));
        $this->assertSame(['17'], $step->assignees()->pluck('assignee_value')->all());
        FlowInstanceStep::whereKey($step->getKey())->update(['finished_at' => now()]);
        try {
            (new MultiUserStrategy)->reassign($step, ['23']);
            $this->fail('Completed steps must reject direct mutation.');
        } catch (\LogicException) {
            $this->assertSame(['17'], $step->assignees()->pluck('assignee_value')->all());
        }
    }

    public function test_definition_and_transition_vetoes_roll_back_the_entire_command(): void
    {
        FlowStep::creating(fn ($step) => $step->id === 'blocked' ? false : null);
        $this->refused(fn () => Artisan::call('flow:define-step', ['stepId' => 'blocked', '--actions' => 'approve:END']));
        $this->assertNull(FlowStep::find('blocked'));
        $this->assertSame(0, FlowTransition::count());
        FlowTransition::creating(fn () => false);
        $this->refused(fn () => Artisan::call('flow:define-step', ['stepId' => 'allowed', '--actions' => 'approve:END']));
        $this->assertNull(FlowStep::find('allowed'));
    }

    public function test_existing_definition_update_veto_preserves_saved_state(): void
    {
        FlowStep::create(['id' => 'review', 'name' => 'Before']);
        FlowStep::updating(fn () => false);
        $this->refused(fn () => Artisan::call('flow:define-step', ['stepId' => 'review', 'name' => 'After', '--actions' => 'approve:END']));
        $this->assertSame('Before', FlowStep::findOrFail('review')->name);
        $this->assertSame(0, FlowTransition::count());
    }

    public function test_transition_instance_update_refresh_and_delete_use_the_saved_tuple(): void
    {
        FlowTransition::create(['step_id' => 'review', 'action' => 'approve', 'next_step_id' => 'END']);
        FlowTransition::create(['step_id' => 'review', 'action' => 'approve', 'next_step_id' => 'other']);
        $transition = FlowTransition::where('next_step_id', 'END')->sole();
        $this->assertTrue($transition->update(['action' => 'reject', 'next_step_id' => 'rework']));
        $this->assertSame('reject', $transition->refresh()->action);
        $this->assertTrue($transition->delete());
        $this->assertSame(1, FlowTransition::count());
        $this->assertSame('other', FlowTransition::sole()->next_step_id);
    }

    public function test_dirty_and_stale_step_bindings_cannot_reassign_another_subject(): void
    {
        Schema::create('flow_test_subjects', fn (Blueprint $table) => $table->id());
        $a = FlowSubject::create(['id' => 1]);
        $b = FlowSubject::create(['id' => 2]);
        FlowStep::create(['id' => 'review', 'assignment_strategy' => 'multi_user', 'assignment_params' => ['user_ids' => [17]]]);
        $aFlow = $a->startFlow('review');
        $aStep = $aFlow->steps()->sole();
        $bFlow = $b->startFlow('review');
        $bStep = $bFlow->steps()->sole();
        $bStep->flow_instance_id = $aFlow->getKey();
        foreach ([$bStep, $aStep] as $candidate) {
            if ($candidate === $aStep) {
                FlowInstanceStep::whereKey($aStep->getKey())->update(['flow_instance_id' => $bFlow->getKey()]);
            }
            try {
                $a->flowReassign($candidate, new MockUser(17), [23]);
                $this->fail('The persisted subject binding must be checked.');
            } catch (\InvalidArgumentException) {
                $this->assertSame(['17'], $candidate->assignees()->pluck('assignee_value')->all());
            }
        }
    }

    public function test_sqlite_subject_keys_round_trip_to_the_signed_boundary_and_reject_larger_values(): void
    {
        if ((new FlowInstance)->getConnection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite signed-range boundary; MySQL unsigned range is covered separately.');
        }
        FlowStep::create(['id' => 'review']);
        foreach (['9223372036854775806', '9223372036854775807'] as $key) {
            $subject = new class($key)
            {
                public function __construct(private string $key) {}

                public function getKey(): string
                {
                    return $this->key;
                }
            };
            $this->assertSame($key, (string) FlowManager::startFlow('review', $subject)->fresh()->model_id);
        }
        foreach (['9223372036854775808', '18446744073709551615'] as $key) {
            $subject = new class($key)
            {
                public function __construct(private string $key) {}

                public function getKey(): string
                {
                    return $this->key;
                }
            };
            try {
                FlowManager::startFlow('review', $subject);
                $this->fail('Unsupported integer range must be rejected before write.');
            } catch (\InvalidArgumentException) {
                $this->assertSame(2, FlowInstance::count());
            }
        }
    }

    public function test_label_zero_remains_unchanged_on_create_update_and_command(): void
    {
        $step = FlowStep::create(['id' => 'review', 'name' => '0']);
        $this->assertSame('0', $step->fresh()->name);
        $step->update(['name' => 'changed']);
        $step->update(['name' => '0']);
        $this->assertSame('0', $step->fresh()->name);
        $this->assertSame(0, Artisan::call('flow:define-step', ['stepId' => 'command', 'name' => '0']));
        $this->assertSame('0', FlowStep::findOrFail('command')->name);
    }

    public function test_configurable_resource_limits_reject_before_writes(): void
    {
        $step = $this->workflow();
        FlowTransition::create(['step_id' => 'review', 'action' => 'approve', 'next_step_id' => 'END']);
        config(['flow.max_transitions_per_action' => 1, 'flow.max_assignments' => 1]);
        try {
            FlowManager::actionStep($step, 'approve');
            $this->fail('Fanout budget must reject the action.');
        } catch (\InvalidArgumentException) {
            $this->assertNull($step->fresh()->finished_at);
            $this->assertSame(1, FlowInstanceStep::count());
        }
        try {
            AssignmentValues::users(['first', 'second']);
            $this->fail('Assignment budget must reject the input.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(['17'], $step->assignees()->pluck('assignee_value')->all());
        }
    }

    public function test_saved_identity_normalization_retains_validation_without_new_admission_limits(): void
    {
        config(['flow.max_assignments' => 1]);
        $this->assertSame(['1', '2'], AssignmentValues::usersForStored([1, 2]));
        $this->assertSame(['one@example.com', 'two@example.com'], AssignmentValues::emailsForStored(['one@example.com', 'two@example.com']));
        foreach ([fn () => AssignmentValues::users([1, 2]), fn () => AssignmentValues::emails(['one@example.com', 'two@example.com']), fn () => AssignmentValues::usersForStored([1, '1']), fn () => AssignmentValues::usersForStored(["invalid\nidentity"]), fn () => AssignmentValues::emailsForStored(['invalid'])] as $operation) {
            try {
                $operation();
                $this->fail('Invalid or over-budget admission accepted');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_morph_conversion_preview_exposes_budget_and_apply_rejects_over_budget(): void
    {
        Relation::morphMap(['subject' => FlowSubject::class], false);
        foreach ([1, 2] as $key) {
            FlowInstance::create(['model_type' => FlowSubject::class, 'model_id' => $key, 'current_step_id' => 'END']);
        }
        config(['flow.max_morph_conversion_rows' => 1]);
        $preview = (new MorphMapMigration)->preview();
        $this->assertSame(2, $preview['total']);
        $this->assertFalse($preview['diagnostics']['within_budget']);
        $this->assertTrue($preview['diagnostics']['locks_all_rows_on_apply']);
        $this->assertGreaterThanOrEqual(0, $preview['diagnostics']['elapsed_ms']);
        try {
            (new MorphMapMigration)->apply($preview['fingerprint']);
            $this->fail('Conversion budget must be reviewed before apply.');
        } catch (\LogicException) {
            $this->assertSame(2, FlowInstance::where('model_type', FlowSubject::class)->count());
        }
    }

    public function test_conversion_batches_retain_the_reviewed_fingerprint_and_saved_keys(): void
    {
        Relation::morphMap(['subject' => FlowSubject::class], false);
        foreach (array_chunk(range(1, 1001), 250) as $keys) {
            FlowInstance::query()->insert(array_map(fn ($key) => [
                'model_type' => FlowSubject::class, 'model_id' => $key, 'current_step_id' => 'END',
            ], $keys));
        }
        $migration = new MorphMapMigration;
        $preview = $migration->preview();
        $this->assertSame(1001, $preview['total']);
        $this->assertSame($preview['fingerprint'], $migration->preview()['fingerprint']);
        $this->assertSame(1001, $migration->apply($preview['fingerprint'])['converted']);
        $this->assertSame(1001, FlowInstance::where('model_type', 'subject')->count());
        $this->assertSame('1', (string) FlowInstance::orderBy('id')->firstOrFail()->model_id);
        $this->assertSame('1001', (string) FlowInstance::orderByDesc('id')->firstOrFail()->model_id);
    }

    public function test_command_output_and_validation_errors_have_translation_hooks(): void
    {
        app('translator')->addLines([
            'messages.commands.defined' => 'Étape [:step] enregistrée.',
            'messages.errors.email_assignees_must_contain_valid_addresses' => 'Adresse invalide.',
        ], 'fr', 'laravel-flow');
        app()->setLocale('fr');
        $this->assertSame(0, Artisan::call('flow:define-step', ['stepId' => 'review']));
        $this->assertStringContainsString('Étape [review] enregistrée.', Artisan::output());
        $this->expectExceptionMessage('Adresse invalide.');
        AssignmentValues::emails(['invalid']);
    }
}
