<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Credentials for a container registry. They are written to a throwaway
 * Docker config for each push (locally) and pull (on the server), never to
 * your own ~/.docker or the server's.
 *
 * @property int $id
 * @property string $name
 * @property string $host
 * @property string $username
 * @property string $password
 */
#[Fillable(['name', 'host', 'username', 'password'])]
#[Hidden(['password'])]
class Registry extends Model
{
    /** registry.example.com, localhost:5000 or docker.io */
    public const string HOST_PATTERN = '~^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:[0-9]{1,5})?$~';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
        ];
    }

    /**
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /** Whether images of $repository live on this registry. */
    public function serves(string $repository): bool
    {
        // Docker's rule: the first component is a host only if it looks like one.
        $first = explode('/', $repository)[0];
        $host = str_contains($first, '.') || str_contains($first, ':') || $first === 'localhost' ? $first : 'docker.io';

        return $host === $this->host;
    }

    /** Contents of a Docker config.json that logs in to this registry only. */
    public function dockerConfig(): string
    {
        $key = $this->host === 'docker.io' ? 'https://index.docker.io/v1/' : $this->host;

        return json_encode([
            'auths' => [$key => ['auth' => $this->encodedAuth()]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Values that must never appear in a deployment log.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        return [$this->password, $this->encodedAuth()];
    }

    private function encodedAuth(): string
    {
        return base64_encode($this->username.':'.$this->password);
    }
}
