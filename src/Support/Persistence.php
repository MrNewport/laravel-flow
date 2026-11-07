<?php

declare(strict_types=1);

namespace MrNewport\LaravelFlow\Support;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Preserve Eloquent observer vetoes as failures of the enclosing operation. */
final class Persistence
{
    public static function save(Model $model): void
    {
        if (! $model->save()) {
            throw new RuntimeException(Text::get('errors.a_workflow_write_was_cancelled_by_a_model_observer'));
        }
    }

    /**
     * @template T of Model
     *
     * @param  T  $model
     * @return T
     */
    public static function created(Model $model): Model
    {
        if (! $model->exists || ($model->getIncrementing() && $model->getKey() === null)) {
            throw new RuntimeException(Text::get('errors.a_workflow_write_was_cancelled_by_a_model_observer'));
        }

        return $model;
    }
}
