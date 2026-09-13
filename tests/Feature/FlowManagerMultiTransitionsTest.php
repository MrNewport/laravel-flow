<?php

namespace MrNewport\LaravelFlow\Tests\Feature;

use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Models\FlowTransition;
use MrNewport\LaravelFlow\Tests\TestCase;

class FlowManagerMultiTransitionsTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function step_has_two_actions_approve_and_reject()
    {
        FlowStep::create(['id'=>'draft','notify'=>false]);
        FlowStep::create(['id'=>'endApproved','notify'=>false]);
        FlowStep::create(['id'=>'endRejected','notify'=>false]);

        FlowTransition::create(['step_id'=>'draft','action'=>'approve','next_step_id'=>'endApproved']);
        FlowTransition::create(['step_id'=>'draft','action'=>'reject','next_step_id'=>'endRejected']);

        $entity = new class{ public $id=808; public function getKey(){return $this->id;}};
        $instance = FlowManager::startFlow('draft',$entity);

        $draftStep = $instance->steps()->whereNull('finished_at')->first();
        $approveResult = FlowManager::actionStep($draftStep,'approve');
        $this->assertCount(1,$approveResult);
        $this->assertEquals('endApproved',$approveResult[0]->step_id);

        // The old step is closed now
        $draftStep->refresh();
        $this->assertEquals('approve',$draftStep->action_taken);

        // We can also do a separate flow or scenario if step not done
        // If a flow had multiple transitions that all match?
        // Then each one is created. But typically action is unique
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function if_multiple_transitions_match_same_action_all_are_created()
    {
        // Rare scenario: same step_id, same action, different next_step_id
        // We'll create 2 transitions with same action
        FlowStep::create(['id'=>'multi_out','notify'=>false]);
        FlowStep::create(['id'=>'end1','notify'=>false]);
        FlowStep::create(['id'=>'end2','notify'=>false]);

        FlowTransition::create(['step_id'=>'multi_out','action'=>'done','next_step_id'=>'end1']);
        FlowTransition::create(['step_id'=>'multi_out','action'=>'done','next_step_id'=>'end2']);

        $entity = new class { public $id=909; public function getKey(){return $this->id;}};
        $instance = FlowManager::startFlow('multi_out',$entity);
        $step = $instance->steps()->whereNull('finished_at')->first();

        $results = FlowManager::actionStep($step,'done');
        $this->assertCount(2,$results);
        $this->assertNull($results[0]->action_taken);
        $this->assertNull($results[1]->action_taken);

        // The instance's current_step_id is updated to the LAST next step
        // In our system, the last iteration sets current_step_id
        $instance->refresh();
        $this->assertEquals('end2',$instance->current_step_id);
    }
}
