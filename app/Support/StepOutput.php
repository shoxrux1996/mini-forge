<?php

declare(strict_types=1);

namespace App\Support;

final class StepOutput
{
    /** Step logs keep the tail; full logs stay on the server. */
    public const int MAX_BYTES = 16_000;

    public static function tail(string $output): string
    {
        $output = trim($output);

        if (strlen($output) <= self::MAX_BYTES) {
            return $output;
        }

        return "[… earlier output trimmed]\n".mb_strcut($output, -self::MAX_BYTES);
    }
}
