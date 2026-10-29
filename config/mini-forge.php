<?php

declare(strict_types=1);

return [
    // Everything Mini Forge manages on a server lives under this directory.
    'remote_root' => env('MINI_FORGE_REMOTE_ROOT', '/opt/mini-forge'),

    'ssh' => [
        // Seconds to wait for the TCP connection and SSH handshake.
        'connect_timeout' => (int) env('MINI_FORGE_SSH_CONNECT_TIMEOUT', 10),
        // Default limit for a single remote command.
        'command_timeout' => (int) env('MINI_FORGE_SSH_COMMAND_TIMEOUT', 300),
    ],
];
