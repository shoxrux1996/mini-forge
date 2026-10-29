<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Data\CommandResult;
use RuntimeException;

class RemoteCommandFailed extends RuntimeException
{
    public ?CommandResult $result = null;

    public static function from(string $what, CommandResult $result): self
    {
        $reason = $result->timedOut ? 'timed out' : "failed with exit code {$result->exitCode}";

        // The cause is almost always at the end of the output.
        $lines = preg_split('/\R/', trim($result->errorOutput ?: $result->output)) ?: [];
        $detail = mb_strimwidth(implode("\n", array_slice($lines, -5)), 0, 1000, '…');

        $exception = new self(trim("{$what} {$reason}.\n{$detail}"));
        $exception->result = $result;

        return $exception;
    }
}
