<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImageSource;
use App\Enums\PhpVersion;
use App\Enums\SiteStatus;
use App\Enums\WebRuntime;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'server_id', 'name', 'domain', 'repository_url', 'branch', 'php_version', 'web_runtime',
    'image_source', 'image_repository', 'run_migrations', 'health_check_path',
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

    public function remotePath(): string
    {
        return config('mini-forge.remote_root').'/sites/'.$this->slug;
    }
}
