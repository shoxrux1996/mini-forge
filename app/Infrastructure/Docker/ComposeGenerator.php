<?php

declare(strict_types=1);

namespace App\Infrastructure\Docker;

use App\Models\Server;
use InvalidArgumentException;

final class ComposeGenerator
{
    /** The shared Traefik stack in /opt/mini-forge/infrastructure. */
    public function infrastructure(Server $server): string
    {
        if (blank($server->acme_email) || filter_var($server->acme_email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Set a valid Let\'s Encrypt email on the server first.');
        }

        return view('templates::infrastructure.compose', [
            'network' => config('mini-forge.network'),
            'acmeEmail' => $server->acme_email,
            'traefikImage' => config('mini-forge.images.traefik'),
            'socketProxyImage' => config('mini-forge.images.socket_proxy'),
        ])->render();
    }
}
