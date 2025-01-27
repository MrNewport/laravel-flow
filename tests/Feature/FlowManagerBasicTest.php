<?php

namespace MrNewport\LaravelFlow\Tests\Feature;

use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Models\FlowTransition;
use MrNewport\LaravelFlow\Tests\TestCase;

class FlowManagerBasicTest extends TestCase
{
    /** @test */
    public function it_starts_a_flow_instance_on_a_step()
    {
        FlowStep::create(['id'=>'startStep','name'=>'Start','notify'=>false]);

        $entity = new class {
            public $id=101;
            public function getKey(){return $this->id;}
        };

        $instance = FlowManager::startFlow('startStep',$entity);
        $this->assertNotNull($instance->id);
        $this->assertEquals('startStep',$instance->current_step_id);
        $this->assertDatabaseHas('flow_instance_steps',[
            'flow_instance_id'=>$instance->id,
            'step_id'=>'startStep'
        ]);
    }

    /** @test */
    public function action_step_closes_current_and_creates_next()
    {
        FlowStep::create(['id'=>'supplier_submit','notify'=>false]);
        FlowTransition::create(['step_id'=>'brand_review','action'=>'approve','next_step_id'=>'supplier_submit']);
        FlowStep::create(['id'=>'brand_review','name'=>'BrandReview','notify'=>false]);

        $entity = new class {
            public $id=202;
            public function getKey(){return $this->id;}
        };

        $instance = FlowManager::startFlow('brand_review',$entity);
        $oldStep = $instance->steps()->whereNull('finished_at')->first();
        $this->assertNull($oldStep->action_taken);

        $newSteps = FlowManager::actionStep($oldStep,'approve');
        $this->assertCount(1,$newSteps);
        $this->assertNotNull($newSteps[0]);

        $oldStep->refresh();
        $this->assertNotNull($oldStep->finished_at);
        $this->assertEquals('approve',$oldStep->action_taken);
        $instance->refresh();
        $this->assertEquals('supplier_submit',$instance->current_step_id);
    }

    /** @test */
    public function action_step_with_no_transitions_does_nothing()
    {
        FlowStep::create(['id'=>'no_next','notify'=>false]);
        $entity = new class { public $id=303; public function getKey(){return $this->id;}};

        $instance = FlowManager::startFlow('no_next',$entity);
        $oldStep = $instance->steps()->whereNull('finished_at')->first();

        $result = FlowManager::actionStep($oldStep,'something');
        $this->assertEmpty($result);

        $oldStep->refresh();
        $this->assertEquals('something',$oldStep->action_taken);
        $this->assertNotNull($oldStep->finished_at);

        $instance->refresh();
        $this->assertEquals('no_next',$instance->current_step_id); // still same
    }
}
