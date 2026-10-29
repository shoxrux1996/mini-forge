<?php

declare(strict_types=1);

namespace App\Infrastructure\SSH;

use App\Contracts\ServerConnection;
use App\Contracts\ServerConnector;
use App\Exceptions\HostKeyMismatch;
use App\Exceptions\ServerConnectionFailed;
use App\Models\Server;
use phpseclib4\Net\SFTP;
use Throwable;

final class SshConnector implements ServerConnector
{
    public function connect(Server $server, ?string $expectedFingerprint): ServerConnection
    {
        $ssh = new SFTP($server->host, $server->ssh_port, (int) config('mini-forge.ssh.connect_timeout'));

        try {
            // Completes the key exchange, so the host key is known before
            // any credentials are offered.
            $hostKey = $ssh->getServerPublicHostKey();
        } catch (Throwable $e) {
            throw new ServerConnectionFailed("Could not reach {$server->host}:{$server->ssh_port}: {$e->getMessage()}", previous: $e);
        }

        if ($hostKey === null) {
            throw new ServerConnectionFailed("{$server->host}:{$server->ssh_port} did not present an SSH host key.");
        }

        $fingerprint = HostKey::fingerprint($hostKey);

        if ($expectedFingerprint !== null && ! hash_equals($expectedFingerprint, $fingerprint)) {
            $ssh->disconnect();

            throw HostKeyMismatch::between($expectedFingerprint, $fingerprint);
        }

        try {
            $loggedIn = $ssh->login($server->ssh_user, $server->privateKey());
        } catch (Throwable $e) {
            throw new ServerConnectionFailed("SSH login failed: {$e->getMessage()}", previous: $e);
        }

        if (! $loggedIn) {
            throw new ServerConnectionFailed(
                "The server refused the key for {$server->ssh_user}. Check that the public key is in ~/.ssh/authorized_keys."
            );
        }

        $ssh->enableQuietMode();
        $ssh->setKeepAlive(30);

        return new SshConnection($ssh, $server->ssh_user, $fingerprint, (int) config('mini-forge.ssh.command_timeout'));
    }
}
