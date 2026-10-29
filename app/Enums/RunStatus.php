<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Status of a long-running operation (a deployment or a provisioning run)
 * and of each of its steps.
 */
enum RunStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending, self::Skipped => 'gray',
            self::Running => 'info',
            self::Succeeded => 'success',
            self::Failed => 'danger',
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Skipped], true);
    }
}
