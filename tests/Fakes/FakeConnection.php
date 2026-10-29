<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\ServerConnection;
use App\Data\CommandResult;
use Closure;

/**
 * Records scripts and answers them from rules: the first rule whose regex
 * matches the script decides the result. Unmatched scripts succeed with no
 * output.
 */
final class FakeConnection implements ServerConnection
{
    /** @var list<string> */
    public array $scripts = [];

    /** @var array<string, array{contents: string, mode: int}> */
    public array $uploads = [];

    /** @var list<array{string, CommandResult|Closure(string): CommandResult}> */
    private array $rules = [];

    public bool $disconnected = false;

    public function __construct(public string $fingerprint = 'SHA256:fake-host-key') {}

    /** @param  CommandResult|string|Closure(string): CommandResult  $result */
    public function on(string $pattern, CommandResult|string|Closure $result): self
    {
        array_unshift($this->rules, [$pattern, is_string($result) ? new CommandResult(0, $result) : $result]);

        return $this;
    }

    public function fail(string $pattern, string $error = 'failed', int $exitCode = 1): self
    {
        return $this->on($pattern, new CommandResult($exitCode, '', $error));
    }

    public function run(string $script, ?int $timeout = null): CommandResult
    {
        $this->scripts[] = $script;

        foreach ($this->rules as [$pattern, $result]) {
            if (preg_match($pattern, $script) === 1) {
                return $result instanceof Closure ? $result($script) : $result;
            }
        }

        return new CommandResult(0, '');
    }

    public function upload(string $path, string $contents, int $mode = 0600): void
    {
        $this->uploads[$path] = ['contents' => $contents, 'mode' => $mode];
    }

    public function hostKeyFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function disconnect(): void
    {
        $this->disconnected = true;
    }

    public function ran(string $pattern): bool
    {
        return collect($this->scripts)->contains(fn (string $script) => preg_match($pattern, $script) === 1);
    }
}
