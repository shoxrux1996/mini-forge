<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProvisioningStatus;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use phpseclib4\Crypt\Common\PrivateKey;
use phpseclib4\Crypt\PublicKeyLoader;

/**
 * @property int $id
 * @property string $name
 * @property string $host
 * @property int $ssh_port
 * @property string $ssh_user
 * @property string $ssh_private_key
 * @property ?string $ssh_key_passphrase
 * @property ?string $ssh_host_key_fingerprint
 * @property ?string $acme_email
 * @property ?string $os_name
 * @property ?string $os_version
 * @property ?string $architecture
 * @property ?int $cpu_cores
 * @property ?int $memory_mb
 * @property ?int $disk_gb
 * @property ?int $uptime_seconds
 * @property ?string $docker_version
 * @property ?string $compose_version
 * @property ?Carbon $inspected_at
 * @property ProvisioningStatus $provisioning_status
 * @property ?Carbon $last_connected_at
 * @property ?string $last_error
 */
#[Fillable([
    'name', 'host', 'ssh_port', 'ssh_user', 'ssh_private_key', 'ssh_key_passphrase', 'acme_email',
])]
#[Hidden(['ssh_private_key', 'ssh_key_passphrase'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    /** A server counts as reachable this long after its last successful connection. */
    public const int REACHABLE_FOR_MINUTES = 15;

    protected $attributes = [
        'ssh_port' => 22,
        'ssh_user' => 'root',
        'provisioning_status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ssh_port' => 'integer',
            'uptime_seconds' => 'integer',
            'ssh_private_key' => 'encrypted',
            'ssh_key_passphrase' => 'encrypted',
            'provisioning_status' => ProvisioningStatus::class,
            'inspected_at' => 'datetime',
            'last_connected_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /**
     * @return HasMany<ServerProvisioningRun, $this>
     */
    public function provisioningRuns(): HasMany
    {
        return $this->hasMany(ServerProvisioningRun::class);
    }

    /**
     * @return HasOne<ServerProvisioningRun, $this>
     */
    public function latestProvisioningRun(): HasOne
    {
        return $this->hasOne(ServerProvisioningRun::class)->latestOfMany();
    }

    public function isReachable(): bool
    {
        return $this->last_connected_at?->gt(now()->subMinutes(self::REACHABLE_FOR_MINUTES)) ?? false;
    }

    public function privateKey(): PrivateKey
    {
        return PublicKeyLoader::loadPrivateKey($this->ssh_private_key, $this->ssh_key_passphrase ?: null);
    }

    /** The line to add to the server's ~/.ssh/authorized_keys. */
    public function publicKey(): string
    {
        return $this->privateKey()->getPublicKey()->toString('OpenSSH', ['comment' => 'mini-forge']);
    }
}
