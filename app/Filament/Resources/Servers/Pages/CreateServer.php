<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servers\Pages;

use App\Actions\Servers\CreateServer as CreateServerAction;
use App\Filament\Resources\Servers\ServerResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateServer extends CreateRecord
{
    protected static string $resource = ServerResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return CreateServerAction::run($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
