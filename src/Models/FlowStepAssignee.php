<?php

namespace MrNewport\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $flow_instance_step_id
 * @property string|null $assignee_type
 * @property string|null $assignee_value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FlowStepAssignee extends Model
{
    use HasFactory;

    protected $table = 'flow_step_assignees';

    protected $fillable = [
        'flow_instance_step_id', 'assignee_type', 'assignee_value',
    ];

    public function instanceStep()
    {
        return $this->belongsTo(FlowInstanceStep::class, 'flow_instance_step_id');
    }
}
