<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Exceptions\RemoteCommandFailed;
use App\Exceptions\ServerConnectionFailed;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class TestConnection
{
    use AsAction;

    /**
     * Connects, checks root access, and returns a one-line summary.
     *
     * @throws ServerConnectionFailed
     * @throws RemoteCommandFailed
     */
    public function handle(Server $server): string
    {
        $connection = ConnectToServer::run($server);

        try {
            // Through sudo -n for non-root users, so this also proves
            // passwordless sudo works.
            $user = trim($connection->run('id -un')->throw('Checking root access')->output);
        } finally {
            $connection->disconnect();
        }

        return "Connected as {$server->ssh_user}; commands run as {$user}. Host key {$server->ssh_host_key_fingerprint}.";
    }
}
