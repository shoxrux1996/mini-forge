<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SiteStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Deploying = 'deploying';
    case Running = 'running';
    case Stopped = 'stopped';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending, self::Stopped => 'gray',
            self::Deploying => 'info',
            self::Running => 'success',
            self::Failed => 'danger',
        };
    }
}
