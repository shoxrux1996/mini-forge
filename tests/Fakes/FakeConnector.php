<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\ServerConnection;
use App\Contracts\ServerConnector;
use App\Exceptions\HostKeyMismatch;
use App\Exceptions\ServerConnectionFailed;
use App\Models\Server;

final class FakeConnector implements ServerConnector
{
    public ?string $unreachable = null;

    public int $connections = 0;

    public function __construct(public FakeConnection $connection = new FakeConnection) {}

    public function connect(Server $server, ?string $expectedFingerprint): ServerConnection
    {
        if ($this->unreachable !== null) {
            throw new ServerConnectionFailed($this->unreachable);
        }

        if ($expectedFingerprint !== null && $expectedFingerprint !== $this->connection->fingerprint) {
            throw HostKeyMismatch::between($expectedFingerprint, $this->connection->fingerprint);
        }

        $this->connections++;

        return $this->connection;
    }
}
