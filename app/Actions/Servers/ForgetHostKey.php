<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class ForgetHostKey
{
    use AsAction;

    /** For a reinstalled server: the next connection pins whatever key it presents. */
    public function handle(Server $server): void
    {
        $server->forceFill(['ssh_host_key_fingerprint' => null])->save();
    }
}
