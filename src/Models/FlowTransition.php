<?php

namespace MrNewport\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Model;

class FlowTransition extends Model
{
    protected $table = 'flow_transitions';
    public $timestamps = false;

    protected $fillable = [
        'step_id','action','next_step_id'
    ];

    public function step()
    {
        return $this->belongsTo(FlowStep::class,'step_id','id');
    }

    public function nextStep()
    {
        return $this->belongsTo(FlowStep::class,'next_step_id','id');
    }
}
