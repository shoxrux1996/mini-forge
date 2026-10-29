<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sites\Pages;

use App\Actions\Sites\CreateSite as CreateSiteAction;
use App\Filament\Resources\Sites\SiteResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSite extends CreateRecord
{
    protected static string $resource = SiteResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return CreateSiteAction::run($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
