<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $server_id
 * @property RunStatus $status
 * @property ?list<array{key: string, label: string, status: string, output: ?string, started_at: ?string, finished_at: ?string}> $steps
 * @property ?string $error
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property-read Server $server
 */
#[Fillable(['status', 'steps', 'error', 'started_at', 'finished_at'])]
class ServerProvisioningRun extends Model
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
            'status' => RunStatus::class,
            'steps' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
