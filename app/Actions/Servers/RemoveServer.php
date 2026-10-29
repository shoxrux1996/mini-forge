<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class RemoveServer
{
    use AsAction;

    /**
     * Forgets the server in Mini Forge only. Nothing on the VPS is touched,
     * so its containers keep running.
     */
    public function handle(Server $server): void
    {
        if ($server->sites()->exists()) {
            throw new RuntimeException('Remove this server\'s sites from Mini Forge first.');
        }

        $server->delete();
    }
}
