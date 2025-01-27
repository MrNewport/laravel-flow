<?php

namespace MrNewport\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class FlowInstanceStep extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'flow_instance_steps';

    protected $fillable = [
        'flow_instance_id','step_id','action_taken','started_at','finished_at'
    ];

    protected $casts = [
        'started_at'=>'datetime',
        'finished_at'=>'datetime'
    ];

    public function flowInstance()
    {
        return $this->belongsTo(FlowInstance::class,'flow_instance_id');
    }

    public function step()
    {
        return $this->belongsTo(FlowStep::class,'step_id','id');
    }

    public function assignees()
    {
        return $this->hasMany(FlowStepAssignee::class,'flow_instance_step_id');
    }

    public function completeStep(string $action)
    {
        $this->action_taken = $action;
        $this->finished_at  = Carbon::now();
        $this->save();
    }
}
