<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sites\Pages;

use App\Actions\Sites\UpdateSite;
use App\Enums\ServiceRole;
use App\Filament\Resources\Sites\SiteResource;
use App\Models\Site;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property Site $record
 */
class EditSite extends EditRecord
{
    protected static string $resource = SiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['queue_enabled'] = $this->record->serviceEnabled(ServiceRole::Worker);
        $data['scheduler_enabled'] = $this->record->serviceEnabled(ServiceRole::Scheduler);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Site $record */
        return UpdateSite::run($record, $data);
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Saved. Apply or deploy to update the server.';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
