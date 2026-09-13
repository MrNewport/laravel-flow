<?php

namespace MrNewport\LaravelFlow\Tests\Feature;

use Illuminate\Support\Facades\Event;
use MrNewport\LaravelFlow\Events\FlowActionEvent;
use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Models\FlowTransition;
use MrNewport\LaravelFlow\Tests\TestCase;

class FlowEventTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function flow_action_event_is_dispatched_when_a_step_is_completed()
    {
        Event::fake([FlowActionEvent::class]);

        FlowStep::create(['id'=>'event_step','notify'=>false]);
        FlowStep::create(['id'=>'next_step','notify'=>false]);
        FlowTransition::create(['step_id'=>'event_step','action'=>'go','next_step_id'=>'next_step']);

        $entity = new class { public $id=1234; public function getKey(){return $this->id;}};
        $instance = FlowManager::startFlow('event_step',$entity);
        $current = $instance->steps()->whereNull('finished_at')->first();

        FlowManager::actionStep($current,'go');

        Event::assertDispatched(FlowActionEvent::class, function(FlowActionEvent $e){
            return $e->oldStep->step_id === 'event_step'
                && $e->action === 'go'
                && $e->newStep?->step_id === 'next_step';
        });
    }
}
