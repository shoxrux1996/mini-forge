<?php

declare(strict_types=1);

namespace App\Infrastructure\SSH;

use InvalidArgumentException;

final class HostKey
{
    /**
     * The fingerprint `ssh-keygen -lf` prints, e.g. SHA256:uNiVztksCsDhcc0u9e8BujQXVUpKZIDTMczCvj3tD2s,
     * from a public key line such as "ssh-ed25519 AAAA...".
     */
    public static function fingerprint(string $publicKey): string
    {
        $parts = explode(' ', trim($publicKey));
        $blob = base64_decode($parts[1] ?? '', true);

        if ($blob === false || $blob === '') {
            throw new InvalidArgumentException('Not an OpenSSH public key.');
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }
}
