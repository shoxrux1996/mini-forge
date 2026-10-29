<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProvisioningStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Ready = 'ready';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Provisioning => 'info',
            self::Ready => 'success',
            self::Failed => 'danger',
        };
    }
}
