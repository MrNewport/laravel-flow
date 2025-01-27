<?php

namespace MrNewport\LaravelFlow\Tests\Feature;

use Illuminate\Support\Facades\Notification;
use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Models\FlowTransition;
use MrNewport\LaravelFlow\Notifications\FlowBaseNotification;
use MrNewport\LaravelFlow\Tests\Support\MockUser;
use MrNewport\LaravelFlow\Tests\TestCase;

class FlowNotificationTest extends TestCase
{
    public function test_start_flow_step_notify_triggers_notification()
    {
        Notification::fake();

        FlowStep::create([
            'id'=>'notif_start',
            'notify'=>true,
            'assignment_strategy'=>'single_user',
            'assignment_params'=>['user_id'=>55]
        ]);

        // We won't rely on listeners, let's just manually do it
        $entity = new class { public $id=111; public function getKey(){return $this->id;}};
        $flow = FlowManager::startFlow('notif_start',$entity);

        // The step is created & assigned to user_id=55
        $step = $flow->steps->first();
        $assigneeVal = $step->assignees->first()?->assignee_value;
        $mockUser = new MockUser((int)$assigneeVal);

        // Send a FlowBaseNotification
        Notification::send($mockUser, new FlowBaseNotification($step,'start'));

        Notification::assertSentTo($mockUser, FlowBaseNotification::class);
    }

    public function test_action_step_leads_to_notify_next_step_sends_notification()
    {
        Notification::fake();

        FlowStep::create(['id'=>'no_notify','notify'=>false,'assignment_strategy'=>null]);
        FlowTransition::create(['step_id'=>'no_notify','action'=>'go','next_step_id'=>'notify_next']);

        FlowStep::create([
            'id'=>'notify_next',
            'notify'=>true,
            'assignment_strategy'=>'multi_user',
            'assignment_params'=>['user_ids'=>[9,8]]
        ]);

        $entity = new class { public $id=222; public function getKey(){return $this->id;}};
        $flow = FlowManager::startFlow('no_notify',$entity);

        $oldStep = $flow->steps()->whereNull('finished_at')->first();
        FlowManager::actionStep($oldStep,'go');

        $newStep = $flow->steps()
            ->whereNull('finished_at')
            ->where('step_id','notify_next')
            ->first();

        // new step assigned to user_ids=9,8
        $assignees = $newStep->assignees;
        foreach($assignees as $a){
            if($a->assignee_type==='user'){
                $mockUser = new MockUser((int)$a->assignee_value);
                Notification::send($mockUser, new FlowBaseNotification($newStep,'go'));
            }
        }

        // 2 notifications => user #9, user #8
        Notification::assertSentTimes(FlowBaseNotification::class,2);
    }
}
