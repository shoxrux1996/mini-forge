<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeploymentTrigger;
use App\Enums\RunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $site_id
 * @property ?int $user_id
 * @property DeploymentTrigger $trigger
 * @property RunStatus $status
 * @property ?string $branch
 * @property ?string $commit_sha
 * @property ?string $image
 * @property ?string $previous_image
 * @property ?string $error
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property-read Site $site
 * @property-read ?User $user
 * @property-read Collection<int, DeploymentStep> $steps
 */
#[Fillable([
    'user_id', 'trigger', 'status', 'branch', 'commit_sha', 'image', 'previous_image', 'error', 'started_at', 'finished_at',
])]
class Deployment extends Model
{
    protected $attributes = [
        'trigger' => 'manual',
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => DeploymentTrigger::class,
            'status' => RunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<DeploymentStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(DeploymentStep::class)->orderBy('position');
    }

    public function durationInSeconds(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at);
    }
}
