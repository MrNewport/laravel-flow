<?php

namespace MrNewport\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $step_id
 * @property string $action
 * @property string $next_step_id
 */
class FlowTransition extends Model
{
    protected $table = 'flow_transitions';

    public $timestamps = false;

    public $incrementing = false;

    // The existing unique tuple is the persistence identity; no saved-data rewrite is needed.
    protected function setKeysForSaveQuery($query)
    {
        foreach (['step_id', 'action', 'next_step_id'] as $column) {
            $query->where($column, $this->getRawOriginal($column) ?? $this->getAttribute($column));
        }

        return $query;
    }

    protected function setKeysForSelectQuery($query)
    {
        return $this->setKeysForSaveQuery($query);
    }

    protected $fillable = [
        'step_id', 'action', 'next_step_id',
    ];

    /** @return BelongsTo<FlowStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(FlowStep::class, 'step_id', 'id');
    }

    /** @return BelongsTo<FlowStep, $this> */
    public function nextStep(): BelongsTo
    {
        return $this->belongsTo(FlowStep::class, 'next_step_id', 'id');
    }
}
