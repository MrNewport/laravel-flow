<?php

namespace MrNewport\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FlowInstance extends Model
{
    use HasFactory;

    protected $table = 'flow_instances';
    protected $fillable = [
        'current_step_id','model_type','model_id'
    ];

    public function currentStep()
    {
        return $this->belongsTo(FlowStep::class,'current_step_id','id');
    }

    public function entity()
    {
        return $this->morphTo(__FUNCTION__,'model_type','model_id');
    }

    public function steps()
    {
        return $this->hasMany(FlowInstanceStep::class,'flow_instance_id');
    }
}
