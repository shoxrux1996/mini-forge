<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sites\Pages;

use App\Actions\Sites\ApplySiteConfiguration;
use App\Actions\Sites\RemoveSite;
use App\Actions\Sites\RestartSite;
use App\Actions\Sites\StartSite;
use App\Actions\Sites\StopSite;
use App\Enums\SiteStatus;
use App\Filament\Resources\Sites\SiteResource;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;
use RuntimeException;

/**
 * @property Site $record
 */
class ViewSite extends ViewRecord
{
    protected static string $resource = SiteResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): string
    {
        return 'Overview';
    }

    protected function getHeaderActions(): array
    {
        return [
            self::applyAction(),
            Action::make('start')
                ->icon(Heroicon::OutlinedPlay)
                ->color('gray')
                ->visible(fn (Site $record) => $record->current_image !== null && $record->status !== SiteStatus::Running)
                ->action(fn (Site $record) => $this->attempt(fn () => StartSite::run($record), 'Site started')),
            Action::make('restart')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (Site $record) => $record->current_image !== null && $record->status === SiteStatus::Running)
                ->requiresConfirmation()
                ->modalDescription('The site is briefly unavailable while its containers restart.')
                ->action(fn (Site $record) => $this->attempt(fn () => RestartSite::run($record), 'Site restarted')),
            Action::make('stop')
                ->icon(Heroicon::OutlinedStop)
                ->color('gray')
                ->visible(fn (Site $record) => $record->status === SiteStatus::Running)
                ->requiresConfirmation()
                ->modalDescription('The site goes offline until you start it again. Containers, files and data are kept.')
                ->action(fn (Site $record) => $this->attempt(fn () => StopSite::run($record), 'Site stopped')),
            EditAction::make()->label('Settings'),
            ActionGroup::make([
                Action::make('remove')
                    ->label('Remove site')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->modalHeading(fn (Site $record) => "Remove {$record->domain}?")
                    ->modalDescription('Stops and removes the containers and the site directory on the server, clears its Redis data, and deletes it from Mini Forge.')
                    ->schema([
                        Toggle::make('delete_data')
                            ->label('Also delete the Postgres database and the storage volume')
                            ->helperText('Cannot be undone. Leave off to keep them on the server.'),
                        TextInput::make('confirmation')
                            ->label(fn (Site $record) => "Type {$record->domain} to confirm")
                            ->required()
                            ->in(fn (Site $record) => [$record->domain])
                            ->validationMessages(['in' => 'Type the domain exactly.']),
                    ])
                    ->modalSubmitActionLabel('Remove')
                    ->action(function (Site $record, array $data): void {
                        try {
                            RemoveSite::run($record, (bool) $data['delete_data']);
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Removing failed')->body($e->getMessage())->persistent()->send();

                            return;
                        }

                        Notification::make()->success()->title("{$record->domain} removed")->send();
                        $this->redirect(SiteResource::getUrl('index'));
                    }),
            ]),
        ];
    }

    public static function applyAction(): Action
    {
        return Action::make('apply')
            ->label('Apply configuration')
            ->icon(Heroicon::OutlinedCloudArrowUp)
            ->requiresConfirmation()
            ->modalDescription(fn (Site $record) => $record->current_image !== null && $record->status !== SiteStatus::Stopped
                ? 'Writes .env and compose.yaml to the server and recreates the containers. The site is briefly unavailable.'
                : 'Creates the site directory, database and Redis user, and writes .env and compose.yaml. Nothing is started until the first deploy.')
            ->action(function (Site $record, Component $livewire): void {
                try {
                    ApplySiteConfiguration::run($record);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Applying failed')->body($e->getMessage())->persistent()->send();

                    return;
                } finally {
                    $livewire->dispatch('site-changed');
                }

                Notification::make()->success()->title('Configuration applied')->send();
            });
    }

    /**
     * @param  callable(): mixed  $operation
     */
    private function attempt(callable $operation, string $success): void
    {
        try {
            $operation();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Failed')->body($e->getMessage())->persistent()->send();

            return;
        } finally {
            $this->record->refresh();
            $this->dispatch('site-changed');
        }

        Notification::make()->success()->title($success)->send();
    }
}
