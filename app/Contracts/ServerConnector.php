<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Exceptions\ServerConnectionFailed;
use App\Models\Server;

interface ServerConnector
{
    /**
     * Opens and authenticates a session. When $expectedFingerprint is given,
     * the server's host key must match it before any credentials are sent.
     *
     * @throws ServerConnectionFailed
     */
    public function connect(Server $server, ?string $expectedFingerprint): ServerConnection;
}
