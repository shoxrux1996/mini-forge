<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ImageSource: string implements HasLabel
{
    // Mini Forge builds the image from the repository and pushes it.
    case Build = 'build';
    // The image is built elsewhere (for example GitHub Actions); Mini Forge
    // only deploys a given tag.
    case Registry = 'registry';

    public function getLabel(): string
    {
        return match ($this) {
            self::Build => 'Build with Mini Forge',
            self::Registry => 'Prebuilt image from registry',
        };
    }
}
