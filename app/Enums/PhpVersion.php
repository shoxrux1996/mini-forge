<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PhpVersion: string implements HasLabel
{
    case Php83 = '8.3';
    case Php84 = '8.4';

    public function getLabel(): string
    {
        return 'PHP '.$this->value;
    }
}
