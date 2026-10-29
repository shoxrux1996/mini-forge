<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servers\Pages;

use App\Actions\Servers\RemoveServer;
use App\Actions\Servers\UpdateServer;
use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EditServer extends EditRecord
{
    protected static string $resource = ServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            Action::make('remove')
                ->label('Remove from Mini Forge')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Mini Forge forgets this server and its stored key. Nothing on the server is changed or deleted; its containers keep running.')
                ->action(function (Server $record): void {
                    try {
                        RemoveServer::run($record);
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title('Server removed from Mini Forge')->send();
                    $this->redirect(ServerResource::getUrl('index'));
                }),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Server $record */
        return UpdateServer::run($record, $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
