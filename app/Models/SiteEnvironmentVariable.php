<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['key', 'value', 'is_sensitive'])]
#[Hidden(['value'])]
class SiteEnvironmentVariable extends Model
{
    /** Valid names for the generated .env file. */
    public const string KEY_PATTERN = '/^[A-Z_][A-Z0-9_]*$/';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'is_sensitive' => 'boolean',
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
