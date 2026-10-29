<?php

declare(strict_types=1);

return [
    // Everything Mini Forge manages on a server lives under this directory.
    'remote_root' => env('MINI_FORGE_REMOTE_ROOT', '/opt/mini-forge'),

    // Shared Docker network that Traefik and every site's web service join.
    'network' => 'mini-forge',

    'images' => [
        'traefik' => env('MINI_FORGE_TRAEFIK_IMAGE', 'traefik:v3.7'),
        'socket_proxy' => env('MINI_FORGE_SOCKET_PROXY_IMAGE', 'tecnativa/docker-socket-proxy:v0.5.0'),
    ],

    'ssh' => [
        // Seconds to wait for the TCP connection and SSH handshake.
        'connect_timeout' => (int) env('MINI_FORGE_SSH_CONNECT_TIMEOUT', 10),
        // Default limit for a single remote command.
        'command_timeout' => (int) env('MINI_FORGE_SSH_COMMAND_TIMEOUT', 300),
    ],
];
