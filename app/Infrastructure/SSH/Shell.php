<?php

declare(strict_types=1);

namespace App\Infrastructure\SSH;

use InvalidArgumentException;

/**
 * The one place remote shell commands are assembled. Every value that is
 * not a literal in our own scripts goes through quote().
 */
final class Shell
{
    public static function quote(string|int $value): string
    {
        return "'".str_replace("'", "'\\''", (string) $value)."'";
    }

    /** A command line with each argument quoted, e.g. command('docker', 'logs', $name). */
    public static function command(string|int ...$arguments): string
    {
        return implode(' ', array_map(self::quote(...), $arguments));
    }

    /**
     * `export NAME='value'` lines to put in front of a script.
     *
     * @param  array<string, string|int>  $variables
     */
    public static function exports(array $variables): string
    {
        $lines = [];

        foreach ($variables as $name => $value) {
            if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $name) !== 1) {
                throw new InvalidArgumentException("Invalid variable name [{$name}].");
            }

            $lines[] = "export {$name}=".self::quote($value);
        }

        return implode("\n", $lines);
    }

    /**
     * Wraps a script so it runs under bash with strict mode, through
     * passwordless sudo unless the session is already root.
     */
    public static function wrap(string $script, bool $sudo): string
    {
        $command = 'bash -c '.self::quote("set -euo pipefail\n".$script);

        return $sudo ? 'sudo -n '.$command : $command;
    }
}
