<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use phpseclib4\Crypt\EC;

class CreateServer
{
    use AsAction;

    /**
     * Without a private key, a new Ed25519 key pair is generated; its public
     * half is shown on the server page for authorized_keys.
     *
     * @param  array{name: string, host: string, ssh_port: int, ssh_user: string, ssh_private_key?: ?string, ssh_key_passphrase?: ?string}  $data
     */
    public function handle(array $data): Server
    {
        if (blank($data['ssh_private_key'] ?? null)) {
            $data['ssh_private_key'] = EC::createKey('Ed25519')->toString('OpenSSH');
            $data['ssh_key_passphrase'] = null;
        }

        return Server::create($data);
    }
}
