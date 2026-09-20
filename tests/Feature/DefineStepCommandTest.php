<?php

namespace MrNewport\LaravelFlow\Tests\Feature;

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
            'notify' => 0
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
            'assignment_strategy' => 'multi_user'
        ]);

        $step = FlowStep::find('alpha_invite');
        $this->assertSame(['user_ids'=>[1,2]], $step->assignment_params);
    }

    public function test_creates_transitions_using_actions_option()
    {
        $this->artisan('flow:define-step beta_review --actions="approve:beta_submit,reject:END"')
            ->expectsOutput('   Action [approve] => Next Step [beta_submit]')
            ->expectsOutput('   Action [reject] => Next Step [END]')
            ->expectsOutput('Step [beta_review] defined/updated successfully.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('flow_transitions', [
            'step_id'=>'beta_review','action'=>'approve','next_step_id'=>'beta_submit'
        ]);
        $this->assertDatabaseHas('flow_transitions', [
            'step_id'=>'beta_review','action'=>'reject','next_step_id'=>'END'
        ]);
    }

    public function test_updates_existing_step()
    {
        FlowStep::create(['id'=>'existing_step','name'=>'Old_Name','notify'=>false]);

        $this->artisan('flow:define-step existing_step Old_name_updated --notify=true')
            ->expectsOutput('Step [existing_step] defined/updated successfully.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('flow_steps', [
            'id'=>'existing_step',
            'name'=>'Old_name_updated',
            'notify'=>true
        ]);
    }

    public function test_handles_invalid_json_for_params_gracefully()
    {
        $this->artisan('flow:define-step gamma_step --params="{INVALID')
            ->expectsOutput('Invalid JSON for --params. Using null instead.')
            ->expectsOutput('Step [gamma_step] defined/updated successfully.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('flow_steps',[
            'id'=>'gamma_step',
            'assignment_params'=>null
        ]);
    }
}
