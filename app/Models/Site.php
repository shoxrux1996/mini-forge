<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImageSource;
use App\Enums\PhpVersion;
use App\Enums\ServiceRole;
use App\Enums\SiteStatus;
use App\Enums\WebRuntime;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @property int $id
 * @property int $server_id
 * @property string $name
 * @property string $slug
 * @property string $domain
 * @property string $repository_url
 * @property string $branch
 * @property PhpVersion $php_version
 * @property WebRuntime $web_runtime
 * @property ImageSource $image_source
 * @property string $image_repository
 * @property ?int $registry_id
 * @property ?string $current_image
 * @property bool $run_migrations
 * @property string $health_check_path
 * @property int $redis_database
 * @property SiteStatus $status
 * @property-read Server $server
 * @property-read ?Registry $registry
 * @property-read Collection<int, DockerService> $services
 * @property-read Collection<int, SiteEnvironmentVariable> $environmentVariables
 */
#[Fillable([
    'server_id', 'name', 'domain', 'repository_url', 'branch', 'php_version', 'web_runtime',
    'image_source', 'image_repository', 'registry_id', 'run_migrations', 'health_check_path',
])]
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    protected $attributes = [
        'branch' => 'main',
        'php_version' => '8.4',
        'web_runtime' => 'frankenphp',
        'image_source' => 'build',
        'run_migrations' => true,
        'health_check_path' => '/up',
        'status' => 'pending',
    ];

    protected static function booted(): void
    {
        // The slug names the Compose project and the remote directory, so it
        // is derived once and never changes with later domain edits.
        static::creating(function (Site $site): void {
            $site->slug ??= Str::slug(str_replace('.', '-', $site->domain));
            $site->redis_database ??= $site->nextFreeRedisDatabase();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'php_version' => PhpVersion::class,
            'web_runtime' => WebRuntime::class,
            'image_source' => ImageSource::class,
            'status' => SiteStatus::class,
            'run_migrations' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<Registry, $this>
     */
    public function registry(): BelongsTo
    {
        return $this->belongsTo(Registry::class);
    }

    /**
     * @return HasMany<Deployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /**
     * @return HasOne<Deployment, $this>
     */
    public function latestDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->latestOfMany();
    }

    /**
     * @return HasMany<DockerService, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(DockerService::class);
    }

    /**
     * @return HasMany<SiteEnvironmentVariable, $this>
     */
    public function environmentVariables(): HasMany
    {
        return $this->hasMany(SiteEnvironmentVariable::class);
    }

    public function markStatus(SiteStatus $status): void
    {
        $this->forceFill(['status' => $status])->save();
    }

    public function remotePath(): string
    {
        return config('mini-forge.remote_root').'/sites/'.$this->slug;
    }

    /** Postgres database and login role, e.g. site_shop_example_com. */
    public function databaseName(): string
    {
        return substr('site_'.str_replace('-', '_', $this->slug), 0, 63);
    }

    /** Redis ACL user. */
    public function redisUser(): string
    {
        return $this->slug;
    }

    /** Every Redis key and channel of this site starts with this. */
    public function redisPrefix(): string
    {
        return $this->slug.':';
    }

    /** Name Docker Compose gives the web container. */
    public function webContainer(): string
    {
        return $this->slug.'-web-1';
    }

    public function serviceEnabled(ServiceRole $role): bool
    {
        return (bool) $this->services->firstWhere('role', $role)?->enabled;
    }

    private function nextFreeRedisDatabase(): int
    {
        $used = static::query()->where('server_id', $this->server_id)->pluck('redis_database')->all();
        $free = array_values(array_diff(range(1, (int) config('mini-forge.redis_databases') - 1), $used));

        if ($free === []) {
            throw new RuntimeException('This server has no free Redis databases left.');
        }

        return $free[0];
    }
}
