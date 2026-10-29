<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servers\Pages;

use App\Actions\Servers\ForgetHostKey;
use App\Actions\Servers\InspectServer;
use App\Actions\Servers\StartProvisioning;
use App\Actions\Servers\TestConnection;
use App\Enums\ProvisioningStatus;
use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * @property Server $record
 */
class ViewServer extends ViewRecord
{
    protected static string $resource = ServerResource::class;

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
            Action::make('testConnection')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->action(fn (Server $record) => $this->attempt(
                    fn () => TestConnection::run($record),
                    'Connection works',
                )),
            Action::make('inspect')
                ->label('Refresh info')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn (Server $record) => $this->attempt(
                    function () use ($record): ?string {
                        InspectServer::run($record);

                        return null;
                    },
                    'Server info refreshed',
                )),
            Action::make('provision')
                ->label(fn (Server $record) => $record->provisioning_status === ProvisioningStatus::Ready ? 'Re-provision' : 'Provision')
                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                ->disabled(fn (Server $record) => $record->provisioning_status === ProvisioningStatus::Provisioning)
                ->requiresConfirmation()
                ->modalHeading('Provision this server?')
                ->modalDescription(<<<'TEXT'
                    Mini Forge will install Docker Engine and the Compose plugin from Docker's official apt repository (skipped if Docker is already installed), enable Docker at boot, create /opt/mini-forge, create the "mini-forge" Docker network, and start Traefik on ports 80 and 443.
                    It does not change SSH settings, users or firewall rules, and stops if ports 80/443 are used by another service. Safe to run again.
                    TEXT)
                ->modalSubmitActionLabel('Provision')
                ->action(function (Server $record): void {
                    try {
                        StartProvisioning::run($record);
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title('Provisioning started')->body('Follow it on the Provisioning logs tab.')->send();
                }),
            EditAction::make()->label('Settings'),
            ActionGroup::make([
                Action::make('forgetHostKey')
                    ->label('Forget host key')
                    ->icon(Heroicon::OutlinedKey)
                    ->color('danger')
                    ->visible(fn (Server $record) => filled($record->ssh_host_key_fingerprint))
                    ->requiresConfirmation()
                    ->modalDescription('Only do this if you reinstalled the server or changed its SSH host key on purpose. The next connection trusts whatever key the server presents.')
                    ->action(function (Server $record): void {
                        ForgetHostKey::run($record);
                        Notification::make()->success()->title('Host key forgotten')->send();
                    }),
            ]),
        ];
    }

    /**
     * Runs a remote operation and reports the result as a notification.
     *
     * @param  callable(): ?string  $operation  returns an optional detail line
     */
    private function attempt(callable $operation, string $success): void
    {
        try {
            $detail = $operation();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Failed')->body($e->getMessage())->persistent()->send();

            return;
        } finally {
            $this->record->refresh();
        }

        Notification::make()->success()->title($success)->body($detail)->send();
    }
}
