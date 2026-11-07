<?php

declare(strict_types=1);

namespace MrNewport\LaravelFlow\Support;

use Illuminate\Container\Container;
use InvalidArgumentException;

final class Limits
{
    public static function value(string $name, int $default): int
    {
        $container = Container::getInstance();
        $value = $container->bound('config') ? $container->make('config')->get('flow.'.$name, $default) : $default;
        if (! is_int($value) || $value < 1 || $value > 1000000) {
            throw new InvalidArgumentException(Text::get('errors.flow_resource_limits_must_be_integers_between_1_and'));
        }

        return $value;
    }
}
