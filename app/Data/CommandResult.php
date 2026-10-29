<?php

declare(strict_types=1);

namespace App\Data;

use App\Exceptions\RemoteCommandFailed;

final readonly class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput = '',
        public bool $timedOut = false,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }

    /** stdout and stderr together, for step logs. */
    public function combinedOutput(): string
    {
        return trim($this->output."\n".$this->errorOutput);
    }

    public function throw(string $what = 'Remote command'): self
    {
        if (! $this->successful()) {
            throw RemoteCommandFailed::from($what, $this);
        }

        return $this;
    }
}
