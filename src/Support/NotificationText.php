<?php

declare(strict_types=1);

namespace MrNewport\LaravelFlow\Support;

final class NotificationText
{
    public static function singleLine(string $value): string
    {
        return str_replace(["\r", "\n"], ' ', $value);
    }
}
