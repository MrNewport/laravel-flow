<?php

declare(strict_types=1);

namespace MrNewport\LaravelFlow\Support;

use Illuminate\Container\Container;
use Illuminate\Support\Arr;

final class Text
{
    /** Built-in validation remains usable before a Laravel application is booted. */
    public static function get(string $key, array $replace = []): string
    {
        $container = Container::getInstance();
        if ($container->bound('translator')) {
            $translated = $container->make('translator')->get('laravel-flow::messages.'.$key, $replace);
            if (is_string($translated) && $translated !== 'laravel-flow::messages.'.$key) {
                return $translated;
            }
        }

        $messages = require __DIR__.'/../lang/en/messages.php';
        $value = Arr::get($messages, $key, $key);
        foreach ($replace as $name => $replacement) {
            $value = str_replace(':'.$name, (string) $replacement, $value);
        }

        return $value;
    }
}
