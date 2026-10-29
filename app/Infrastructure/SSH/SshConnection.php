<?php

declare(strict_types=1);

namespace App\Infrastructure\SSH;

use App\Contracts\ServerConnection;
use App\Data\CommandResult;
use App\Exceptions\RemoteCommandFailed;
use phpseclib4\Net\SFTP;

final class SshConnection implements ServerConnection
{
    public function __construct(
        private readonly SFTP $ssh,
        private readonly string $user,
        private readonly string $fingerprint,
        private readonly int $defaultTimeout,
    ) {}

    public function run(string $script, ?int $timeout = null): CommandResult
    {
        $this->ssh->setTimeout($timeout ?? $this->defaultTimeout);

        $output = (string) $this->ssh->exec(Shell::wrap($script, $this->needsSudo()));

        return new CommandResult(
            exitCode: $this->ssh->getExitStatus() ?? 255,
            output: $output,
            errorOutput: $this->ssh->getStdError(),
            timedOut: $this->ssh->isTimeout(),
        );
    }

    public function upload(string $path, string $contents, int $mode = 0600): void
    {
        $octal = sprintf('%04o', $mode);

        if (! $this->needsSudo()) {
            $this->ssh->put($path, $contents);
            $this->ssh->chmod($path, $mode);

            return;
        }

        // SFTP cannot use sudo: write to the user's home first (mode 600),
        // then move the file into place as root.
        $temporary = rtrim((string) $this->ssh->pwd(), '/').'/.mini-forge-upload-'.bin2hex(random_bytes(8));
        $this->ssh->put($temporary, '');
        $this->ssh->chmod($temporary, 0600);
        $this->ssh->put($temporary, $contents);

        $result = $this->run(
            'install -m '.$octal.' -o root -g root '.Shell::quote($temporary).' '.Shell::quote($path)
            .'; rm -f '.Shell::quote($temporary)
        );

        if (! $result->successful()) {
            $this->ssh->delete($temporary);

            throw RemoteCommandFailed::from("Uploading {$path}", $result);
        }
    }

    public function hostKeyFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function disconnect(): void
    {
        $this->ssh->disconnect();
    }

    private function needsSudo(): bool
    {
        return $this->user !== 'root';
    }
}
