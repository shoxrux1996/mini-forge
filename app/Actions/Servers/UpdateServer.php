<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class UpdateServer
{
    use AsAction;

    /**
     * A blank private key keeps the stored one. Pointing the server at a new
     * address forgets the pinned host key, so the next connection pins again.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Server $server, array $data): Server
    {
        if (blank($data['ssh_private_key'] ?? null)) {
            unset($data['ssh_private_key'], $data['ssh_key_passphrase']);
        }

        $server->fill($data);

        if ($server->isDirty(['host', 'ssh_port'])) {
            $server->ssh_host_key_fingerprint = null;
            $server->last_connected_at = null;
        }

        $server->save();

        return $server;
    }
}
