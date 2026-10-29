<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServiceRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property ServiceRole $role
 * @property bool $enabled
 * @property ?string $command
 * @property ?string $memory_limit
 */
#[Fillable(['role', 'enabled', 'command', 'memory_limit'])]
class DockerService extends Model
{
    protected $attributes = [
        'enabled' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ServiceRole::class,
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
