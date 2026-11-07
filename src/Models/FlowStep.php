<?php

namespace MrNewport\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property bool $notify
 * @property string|null $assignment_strategy
 * @property array|null $assignment_params
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FlowStep extends Model
{
    use HasFactory;

    protected $table = 'flow_steps';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'name', 'notify', 'assignment_strategy', 'assignment_params',
    ];

    protected $casts = [
        'assignment_params' => 'array',
    ];

    protected static function booted()
    {
        static::creating(function ($step) {
            if ($step->name === null || $step->name === '') {
                $step->name = ucfirst($step->id);
            }
        });

        static::updating(function ($step) {
            // If user sets name to empty, fallback
            if ($step->isDirty('name') && ($step->name === null || $step->name === '')) {
                $step->name = ucfirst($step->id);
            }
        });
    }

    /** @return HasMany<FlowTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(FlowTransition::class, 'step_id', 'id');
    }
}
