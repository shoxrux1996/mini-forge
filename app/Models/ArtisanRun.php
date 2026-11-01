<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ArtisanCommand;
use App\Enums\RunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $site_id
 * @property ?int $user_id
 * @property ArtisanCommand $command
 * @property RunStatus $status
 * @property ?int $exit_code
 * @property ?string $output
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?Carbon $created_at
 * @property-read Site $site
 * @property-read ?User $user
 */
#[Fillable(['user_id', 'command', 'status', 'exit_code', 'output', 'started_at', 'finished_at'])]
class ArtisanRun extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'command' => ArtisanCommand::class,
            'status' => RunStatus::class,
            'exit_code' => 'integer',
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
}
