<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\CommandResult;

/**
 * An authenticated session on a managed server. SSH is the only transport
 * today; an agent could implement the same contract later.
 */
interface ServerConnection
{
    /**
     * Runs a bash script with root privileges (directly as root, or through
     * passwordless sudo). The script runs with `set -euo pipefail`; build any
     * user-supplied values into it with Shell::quote() or Shell::exports().
     */
    public function run(string $script, ?int $timeout = null): CommandResult;

    /** Writes a file as root with the given mode, creating nothing else. */
    public function upload(string $path, string $contents, int $mode = 0600): void;

    /** The server's host key as an OpenSSH SHA256 fingerprint. */
    public function hostKeyFingerprint(): string;

    public function disconnect(): void;
}
