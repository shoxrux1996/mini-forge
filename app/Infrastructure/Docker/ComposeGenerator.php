<?php

declare(strict_types=1);

namespace App\Infrastructure\Docker;

use App\Models\Server;
use App\Models\Site;
use InvalidArgumentException;

final class ComposeGenerator
{
    /** The shared stack in /opt/mini-forge/infrastructure: Traefik, Postgres, Redis. */
    public function infrastructure(Server $server): string
    {
        if (blank($server->acme_email) || filter_var($server->acme_email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Set a valid Let\'s Encrypt email on the server first.');
        }

        return view('templates::infrastructure.compose', [
            'network' => config('mini-forge.network'),
            'dataNetwork' => config('mini-forge.data_network'),
            'redisDatabases' => (int) config('mini-forge.redis_databases'),
            'acmeEmail' => $server->acme_email,
            'traefikImage' => config('mini-forge.images.traefik'),
            'socketProxyImage' => config('mini-forge.images.socket_proxy'),
            'postgresImage' => config('mini-forge.images.postgres'),
            'redisImage' => config('mini-forge.images.redis'),
        ])->render();
    }

    /**
     * A site's own Compose project. Disabled services are left out, so
     * `up --remove-orphans` stops them.
     */
    public function site(Site $site): string
    {
        return view('templates::laravel.compose', [
            'site' => $site,
            // Before the first deploy there is no image; the file is still
            // valid and is written so it can be inspected.
            'image' => $site->current_image ?? $site->image_repository.':not-deployed',
            'services' => $site->services()->where('enabled', true)->orderBy('id')->get(),
            'network' => config('mini-forge.network'),
            'dataNetwork' => config('mini-forge.data_network'),
        ])->render();
    }
}
