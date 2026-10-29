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
 * @property int $deployment_id
 * @property int $position
 * @property string $name
 * @property RunStatus $status
 * @property ?string $output
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 */
#[Fillable(['position', 'name', 'status', 'output', 'started_at', 'finished_at'])]
class DeploymentStep extends Model
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
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Deployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
