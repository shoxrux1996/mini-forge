<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class DeploymentStepFailed extends RuntimeException
{
    public static function from(string $step, string $reason, Throwable $previous): self
    {
        return new self("{$step} failed: {$reason}", previous: $previous);
    }
}
