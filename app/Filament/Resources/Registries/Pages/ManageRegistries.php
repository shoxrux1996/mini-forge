<?php

declare(strict_types=1);

namespace App\Filament\Resources\Registries\Pages;

use App\Filament\Resources\Registries\RegistryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRegistries extends ManageRecords
{
    protected static string $resource = RegistryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
