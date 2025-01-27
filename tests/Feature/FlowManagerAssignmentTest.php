<?php

namespace MrNewport\LaravelFlow\Tests\Feature;

use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\{FlowStep,FlowTransition,FlowInstanceStep,FlowStepAssignee};
use MrNewport\LaravelFlow\Tests\TestCase;

class FlowManagerAssignmentTest extends TestCase
{
    /** @test */
    public function start_flow_auto_assigns_using_step_assignment_strategy()
    {
        FlowStep::create([
            'id'=>'multi_assign_step',
            'name'=>'MultiAssign',
            'notify'=>false,
            'assignment_strategy'=>'multi_user',
            'assignment_params'=>['user_ids'=>[5,10]]
        ]);

        $entity = new class { public $id=404; public function getKey(){return $this->id;}};
        $instance = FlowManager::startFlow('multi_assign_step',$entity);

        $instanceStep = $instance->steps()->first();
        $assignees = FlowStepAssignee::where('flow_instance_step_id',$instanceStep->id)->get();

        $this->assertCount(2,$assignees);
        $this->assertEquals('5',$assignees[0]->assignee_value);
        $this->assertEquals('10',$assignees[1]->assignee_value);
    }

    /** @test */
    public function action_step_creates_next_step_with_its_strategy()
    {
        FlowStep::create(['id'=>'start','assignment_strategy'=>null]);
        FlowTransition::create(['step_id'=>'start','action'=>'go','next_step_id'=>'nextA']);

        FlowStep::create([
            'id'=>'nextA',
            'name'=>'NextA',
            'assignment_strategy'=>'email_list',
            'assignment_params'=>['emails'=>['user@example.com','info@foo.com']]
        ]);

        $entity = new class{ public $id=505; public function getKey(){return $this->id;}};
        $instance = FlowManager::startFlow('start',$entity);
        $oldStep = $instance->steps()->whereNull('finished_at')->first();

        $results = FlowManager::actionStep($oldStep,'go');
        $this->assertCount(1,$results);
        $newStep = $results[0];
        $this->assertDatabaseHas('flow_instance_steps',[
            'id'=>$newStep->id,
            'step_id'=>'nextA'
        ]);

        $assignees = FlowStepAssignee::where('flow_instance_step_id',$newStep->id)->get();
        $this->assertCount(2,$assignees);
        $this->assertEquals('email',$assignees[0]->assignee_type);
        $this->assertEquals('user@example.com',$assignees[0]->assignee_value);
    }

    /** @test */
    public function reassign_step_works_for_multi_user()
    {
        $step = FlowStep::create([
            'id'=>'multi_assign_reassign',
            'assignment_strategy'=>'multi_user',
            'assignment_params'=>['user_ids'=>[1,2]]
        ]);

        $entity = new class{ public $id=606; public function getKey(){return $this->id;}};
        $instance = FlowManager::startFlow('multi_assign_reassign',$entity);
        $instanceStep = $instance->steps->first();

        $this->assertDatabaseCount('flow_step_assignees',2);

        $currentUser = new class {
            public $id=1;
        };

        $canReassign = FlowManager::reassignStep($instanceStep,$currentUser,['3','4']);
        $this->assertTrue($canReassign);

        $this->assertDatabaseCount('flow_step_assignees',2);
        $this->assertDatabaseHas('flow_step_assignees',[
            'assignee_value'=>'3'
        ]);
        $this->assertDatabaseHas('flow_step_assignees',[
            'assignee_value'=>'4'
        ]);
    }

    /** @test */
    public function reassign_fails_if_not_in_assignees()
    {
        $step = FlowStep::create([
            'id'=>'single_user_step',
            'assignment_strategy'=>'single_user',
            'assignment_params'=>['user_id'=>10]
        ]);

        $entity = new class { public $id=707; public function getKey(){return $this->id;}};
        $instance = FlowManager::startFlow('single_user_step',$entity);
        $instanceStep = $instance->steps->first();

        $userNotInStep = new class { public $id=999; };

        $result = FlowManager::reassignStep($instanceStep,$userNotInStep,['7']);
        $this->assertFalse($result);
        $this->assertDatabaseHas('flow_step_assignees',[
            'assignee_value'=>'10'
        ]);
    }
}
