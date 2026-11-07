<?php

namespace MrNewport\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $current_step_id
 * @property string $model_type
 * @property int|string $model_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FlowInstance extends Model
{
    use HasFactory;

    protected $table = 'flow_instances';

    protected $fillable = [
        'current_step_id', 'model_type', 'model_id',
    ];

    /** @return BelongsTo<FlowStep, $this> */
    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(FlowStep::class, 'current_step_id', 'id');
    }

    /** @return MorphTo<Model, $this> */
    public function entity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'model_type', 'model_id');
    }

    /** @return HasMany<FlowInstanceStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(FlowInstanceStep::class, 'flow_instance_id');
    }
}
