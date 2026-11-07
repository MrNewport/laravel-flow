<?php

namespace MrNewport\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use MrNewport\LaravelFlow\Support\Persistence;

/**
 * @property int $id
 * @property int $flow_instance_id
 * @property string $step_id
 * @property string|null $action_taken
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FlowInstanceStep extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'flow_instance_steps';

    protected $fillable = [
        'flow_instance_id', 'step_id', 'action_taken', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** @return BelongsTo<FlowInstance, $this> */
    public function flowInstance(): BelongsTo
    {
        return $this->belongsTo(FlowInstance::class, 'flow_instance_id');
    }

    /** @return BelongsTo<FlowStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(FlowStep::class, 'step_id', 'id');
    }

    /** @return HasMany<FlowStepAssignee, $this> */
    public function assignees(): HasMany
    {
        return $this->hasMany(FlowStepAssignee::class, 'flow_instance_step_id');
    }

    /** @internal Use FlowManager::actionStep() for locked pending-state validation. */
    public function completeStep(string $action)
    {
        $this->action_taken = $action;
        $this->finished_at = Carbon::now();
        Persistence::save($this);
    }
}
