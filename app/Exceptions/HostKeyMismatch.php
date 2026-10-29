<?php

declare(strict_types=1);

namespace App\Exceptions;

class HostKeyMismatch extends ServerConnectionFailed
{
    public static function between(string $expected, string $actual): self
    {
        return new self(
            "The server's host key changed (expected {$expected}, got {$actual}). "
            .'This happens after a reinstall, or if someone is intercepting the connection. '
            .'If you reinstalled the server, use "Forget host key" and connect again.'
        );
    }
}
