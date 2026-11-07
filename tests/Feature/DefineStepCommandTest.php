<?php

namespace MrNewport\LaravelFlow\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Models\FlowTransition;
use MrNewport\LaravelFlow\Tests\TestCase;

class DefineStepCommandTest extends TestCase
{
    public function test_rejects_an_unsupported_assignment_strategy_before_writing(): void
    {
        $this->artisan('flow:define-step unsupported --strategy=custom_review')
            ->expectsOutput('Unsupported flow assignment strategy: custom_review')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('flow_steps', ['id' => 'unsupported']);
    }

    public function test_creates_new_step_with_defaults()
    {
        $this->artisan('flow:define-step alpha_import')
            ->expectsOutput('Step [alpha_import] defined/updated successfully.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('flow_steps', [
            'id' => 'alpha_import',
            // fallback name => 'Alpha_import'
            'notify' => 0,
        ]);

        $step = FlowStep::find('alpha_import');
        $this->assertSame('Alpha_import', $step->name);
    }

    public function test_upserts_with_notify_and_strategy()
    {
        $this->artisan('flow:define-step alpha_invite --notify=true --strategy=multi_user --params=\'{"user_ids":[1,2]}\'')
            ->assertExitCode(0)
            ->expectsOutput('Step [alpha_invite] defined/updated successfully.');

        $this->assertDatabaseHas('flow_steps', [
            'id' => 'alpha_invite',
            'notify' => 1,
            'assignment_strategy' => 'multi_user',
        ]);

        $step = FlowStep::find('alpha_invite');
        $this->assertSame(['user_ids' => [1, 2]], $step->assignment_params);
    }

    public function test_creates_transitions_using_actions_option()
    {
        $this->artisan('flow:define-step beta_review --actions="approve:beta_submit,reject:END"')
            ->expectsOutput('   Action [approve] => Next Step [beta_submit]')
            ->expectsOutput('   Action [reject] => Next Step [END]')
            ->expectsOutput('Step [beta_review] defined/updated successfully.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('flow_transitions', [
            'step_id' => 'beta_review', 'action' => 'approve', 'next_step_id' => 'beta_submit',
        ]);
        $this->assertDatabaseHas('flow_transitions', [
            'step_id' => 'beta_review', 'action' => 'reject', 'next_step_id' => 'END',
        ]);
    }

    public function test_updates_existing_step()
    {
        FlowStep::create(['id' => 'existing_step', 'name' => 'Old_Name', 'notify' => false]);

        $this->artisan('flow:define-step existing_step Old_name_updated --notify=true')
            ->expectsOutput('Step [existing_step] defined/updated successfully.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('flow_steps', [
            'id' => 'existing_step',
            'name' => 'Old_name_updated',
            'notify' => true,
        ]);
    }

    public function test_rejects_invalid_json_before_saving_a_definition()
    {
        $this->artisan('flow:define-step gamma_step --params="{INVALID')
            ->expectsOutput('--params must contain valid JSON.')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('flow_steps', ['id' => 'gamma_step']);
    }

    public function test_rejects_a_malformed_transition_without_partially_updating_the_step(): void
    {
        FlowStep::create(['id' => 'review', 'name' => 'Review']);
        $this->artisan('flow:define-step review Changed --actions="approve:END,broken"')->assertExitCode(1);
        $this->assertSame('Review', FlowStep::findOrFail('review')->name);
        $this->assertDatabaseCount('flow_transitions', 0);
    }

    public function test_keeps_colons_in_namespaced_transition_targets(): void
    {
        $this->artisan('flow:define-step review --actions="approve:w:published:next"')->assertExitCode(0);
        $this->assertDatabaseHas('flow_transitions', ['step_id' => 'review', 'action' => 'approve', 'next_step_id' => 'w:published:next']);
    }

    public function test_rolls_back_a_definition_when_persisting_its_transitions_fails(): void
    {
        FlowStep::create(['id' => 'review', 'name' => 'Review']);
        FlowTransition::creating(function ($transition) {
            if ($transition->action === 'fail') {
                throw new \RuntimeException('Transition refused.');
            }
        });
        try {
            Artisan::call('flow:define-step', ['stepId' => 'review', 'name' => 'Changed', '--actions' => 'approve:END,fail:END']);
            $this->fail('The simulated persistence failure must surface.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Transition refused.', $exception->getMessage());
        }
        $this->assertSame('Review', FlowStep::findOrFail('review')->name);
        $this->assertDatabaseCount('flow_transitions', 0);
    }

    public function test_rejects_nonboolean_notification_flags_and_scalar_parameters(): void
    {
        $this->artisan('flow:define-step wrong_flag --notify=maybe')->assertExitCode(1);
        $this->artisan('flow:define-step wrong_params --params=5')->assertExitCode(1);
        $this->assertDatabaseMissing('flow_steps', ['id' => 'wrong_flag']);
        $this->assertDatabaseMissing('flow_steps', ['id' => 'wrong_params']);
    }
}
