<?php

declare(strict_types=1);

namespace App\Infrastructure\Docker;

final class Yaml
{
    /**
     * A double-quoted YAML scalar. JSON strings are valid YAML, so values
     * cannot break out of the template's structure.
     */
    public static function string(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
