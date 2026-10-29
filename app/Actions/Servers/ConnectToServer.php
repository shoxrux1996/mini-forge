<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Contracts\ServerConnection;
use App\Contracts\ServerConnector;
use App\Exceptions\ServerConnectionFailed;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class ConnectToServer
{
    use AsAction;

    public function __construct(private readonly ServerConnector $connector) {}

    /**
     * Every remote operation starts here. The first successful connection
     * pins the server's host key (trust on first use); after that a
     * different key is refused before credentials are sent.
     *
     * @throws ServerConnectionFailed
     */
    public function handle(Server $server): ServerConnection
    {
        try {
            $connection = $this->connector->connect($server, $server->ssh_host_key_fingerprint);
        } catch (ServerConnectionFailed $e) {
            $server->forceFill(['last_error' => $e->getMessage()])->save();

            throw $e;
        }

        $server->forceFill([
            'ssh_host_key_fingerprint' => $server->ssh_host_key_fingerprint ?? $connection->hostKeyFingerprint(),
            'last_connected_at' => now(),
            'last_error' => null,
        ])->save();

        return $connection;
    }
}
