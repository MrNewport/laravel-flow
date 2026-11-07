<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\FlowInstance;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Models\FlowStepAssignee;
use MrNewport\LaravelFlow\Models\FlowTransition;
use MrNewport\LaravelFlow\Tests\Support\FlowSubject;
use MrNewport\LaravelFlow\Tests\Support\MockUser;

function assignedOperationStep(): FlowInstanceStep
{
    FlowStep::create(['id' => 'review', 'assignment_strategy' => 'multi_user', 'assignment_params' => ['user_ids' => [17]]]);
    FlowTransition::create(['step_id' => 'review', 'action' => 'approve', 'next_step_id' => 'END']);

    return FlowManager::startFlow('review', new MockUser(17))->steps()->sole();
}

it('uses persisted pending state when a stale completed step is reassigned', function () {
    $step = assignedOperationStep();
    FlowManager::actionStep($step, 'approve');
    expect($step->finished_at)->toBeNull();
    expect(fn () => FlowManager::reassignStep($step, new MockUser(17), [23]))->toThrow(LogicException::class)
        ->and($step->assignees()->pluck('assignee_value')->all())->toBe(['17']);
});

it('retains the complete assignment set if an assignment listener fails', function () {
    $step = assignedOperationStep();
    FlowStepAssignee::creating(function ($assignment) {
        if ($assignment->assignee_value === 'fail') {
            throw new RuntimeException('Assignment refused.');
        }
    });
    expect(fn () => FlowManager::reassignStep($step, new MockUser(17), ['23', 'fail']))->toThrow(RuntimeException::class)
        ->and($step->assignees()->pluck('assignee_value')->all())->toBe(['17']);
});

it('validates assignment data before changing saved assignments', function () {
    $step = assignedOperationStep();
    foreach ([[null], [['unexpected']], [''], [17, '17']] as $invalid) {
        expect(fn () => FlowManager::reassignStep($step, new MockUser(17), $invalid))->toThrow(InvalidArgumentException::class)
            ->and($step->assignees()->pluck('assignee_value')->all())->toBe(['17']);
    }
});

it('uses an Eloquent user key rather than assuming its primary key is named id', function () {
    $step = assignedOperationStep();
    $user = new class extends Model
    {
        protected $primaryKey = 'reviewer_key';

        protected $guarded = [];
    };
    $user->setAttribute('reviewer_key', 17);
    expect(FlowManager::reassignStep($step, $user, [23]))->toBeTrue()
        ->and($step->assignees()->pluck('assignee_value')->all())->toBe(['23']);
});

it('resolves pending steps after a null relationship was cached on the subject', function () {
    Schema::create('flow_test_subjects', fn (Blueprint $table) => $table->id());
    $subject = FlowSubject::create(['id' => 17]);
    $subject->load('flowInstance');
    expect($subject->flowInstance)->toBeNull();
    FlowStep::create(['id' => 'review']);
    FlowTransition::create(['step_id' => 'review', 'action' => 'approve', 'next_step_id' => 'END']);
    $flow = $subject->startFlow('review');
    $subject->flowAction('approve');
    expect($flow->fresh()->current_step_id)->toBe('END');

    $other = FlowSubject::create(['id' => 18]);
    $other->setRelation('flowInstance', null);
    $external = FlowManager::startFlow('review', $other);
    expect($other->currentFlowStep()->flow_instance_id)->toBe($external->getKey());
});

it('refuses to reassign an unrelated subjects step through the trait', function () {
    Schema::create('flow_test_subjects', fn (Blueprint $table) => $table->id());
    $subject = FlowSubject::create(['id' => 1]);
    $other = FlowSubject::create(['id' => 2]);
    FlowStep::create(['id' => 'review', 'assignment_strategy' => 'single_user', 'assignment_params' => ['user_id' => 17]]);
    $step = $other->startFlow('review')->steps()->sole();
    expect(fn () => $subject->flowReassign($step, new MockUser(17), [23]))->toThrow(InvalidArgumentException::class)
        ->and($step->assignees()->pluck('assignee_value')->all())->toBe(['17']);
});

it('rejects invalid or unsaved subjects before creating workflow records', function () {
    FlowStep::create(['id' => 'review']);
    $unsaved = new FlowSubject(['id' => 17]);
    foreach ([null, new stdClass, $unsaved, new class
    {
        public function getKey()
        {
            return null;
        }
    }, new class
    {
        public function getKey()
        {
            return [];
        }
    }] as $subject) {
        expect(fn () => FlowManager::startFlow('review', $subject))->toThrow(InvalidArgumentException::class);
    }
    expect(FlowInstance::count())->toBe(0);
});

it('rejects keys that do not match the configured subject column', function () {
    FlowStep::create(['id' => 'review']);
    foreach ([-1, '01', '18446744073709551616', 'a95aab23-03c4-4d6f-a6fa-a3e10ad8df90'] as $key) {
        $subject = new class($key)
        {
            public function __construct(private $key) {}

            public function getKey()
            {
                return $this->key;
            }
        };
        expect(fn () => FlowManager::startFlow('review', $subject))->toThrow(InvalidArgumentException::class);
    }
    expect(FlowInstance::count())->toBe(0);
});

it('refuses an Eloquent subject on a different database connection', function () {
    config(['database.connections.other' => config('database.connections.testing')]);
    $subject = (new FlowSubject(['id' => 17]))->setConnection('other');
    $subject->exists = true;
    expect(fn () => FlowManager::startFlow('review', $subject))->toThrow(InvalidArgumentException::class)
        ->and(FlowInstance::count())->toBe(0);
});

it('updates the instance pointer once for a fan-out action', function () {
    FlowStep::create(['id' => 'review']);
    foreach (['one', 'two', 'three'] as $target) {
        FlowStep::create(['id' => $target]);
        FlowTransition::create(['step_id' => 'review', 'action' => 'approve', 'next_step_id' => $target]);
    }
    $flow = FlowManager::startFlow('review', new MockUser(17));
    $step = $flow->steps()->sole();
    DB::enableQueryLog();
    $created = FlowManager::actionStep($step, 'approve');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $grammar = DB::connection()->getQueryGrammar();
    $updates = array_filter($queries, fn ($query) => str_starts_with(strtolower($query['query']), 'update '.$grammar->wrapTable('flow_instances')));
    $definitionReads = array_filter($queries, fn ($query) => str_starts_with(strtolower($query['query']), 'select * from '.$grammar->wrapTable('flow_steps')));
    expect($created)->toHaveCount(3)->and($updates)->toHaveCount(1)
        ->and($definitionReads)->toHaveCount(1)
        ->and($flow->fresh()->current_step_id)->toBe($created[2]->step_id);
});
