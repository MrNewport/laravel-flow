<?php

namespace MrNewport\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FlowStep extends Model
{
    use HasFactory;

    protected $table = 'flow_steps';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id','name','notify','assignment_strategy','assignment_params'
    ];

    protected $casts = [
        'assignment_params' => 'array'
    ];


    protected static function booted()
    {
        static::creating(function($step){
            if(empty($step->name)){
                $step->name = ucfirst($step->id);
            }
        });

        static::updating(function($step){
            // If user sets name to empty, fallback
            if($step->isDirty('name') && empty($step->name)){
                $step->name = ucfirst($step->id);
            }
        });
    }

    public function transitions()
    {
        return $this->hasMany(FlowTransition::class,'step_id','id');
    }
}
