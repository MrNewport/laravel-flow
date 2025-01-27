<?php

namespace MrNewport\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FlowStepAssignee extends Model
{
    use HasFactory;

    protected $table = 'flow_step_assignees';

    protected $fillable = [
        'flow_instance_step_id','assignee_type','assignee_value'
    ];

    public function instanceStep()
    {
        return $this->belongsTo(FlowInstanceStep::class,'flow_instance_step_id');
    }
}
