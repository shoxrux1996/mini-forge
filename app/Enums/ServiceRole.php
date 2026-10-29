<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ServiceRole: string implements HasLabel
{
    case Web = 'web';
    case Worker = 'worker';
    case Scheduler = 'scheduler';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}
