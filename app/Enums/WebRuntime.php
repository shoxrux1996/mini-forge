<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WebRuntime: string implements HasLabel
{
    // One container serving HTTP and PHP. Nginx + PHP-FPM can be added as
    // another case with its own template.
    case FrankenPhp = 'frankenphp';

    public function getLabel(): string
    {
        return match ($this) {
            self::FrankenPhp => 'FrankenPHP',
        };
    }
}
