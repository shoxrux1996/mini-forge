<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DeploymentTrigger: string implements HasLabel
{
    case Manual = 'manual';
    case Redeploy = 'redeploy';
    case Rollback = 'rollback';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}
