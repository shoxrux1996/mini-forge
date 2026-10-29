<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sites\RelationManagers;

use App\Actions\Deployments\StartDeployment;
use App\Enums\DeploymentTrigger;
use App\Enums\RunStatus;
use App\Filament\Resources\Deployments\DeploymentResource;
use App\Filament\Resources\Deployments\Tables\DeploymentsTable;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * @property Site $ownerRecord
 */
class DeploymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'deployments';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return DeploymentsTable::configure($table, withSite: false)
            ->emptyStateHeading('No deployments yet')
            ->recordActions([
                Action::make('rollback')
                    ->label('Roll back')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('warning')
                    ->visible(fn (Deployment $record) => $record->status === RunStatus::Succeeded
                        && $record->image !== null
                        && $record->image !== $this->ownerRecord->current_image)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Deployment $record) => "Roll back to deployment #{$record->id}?")
                    ->modalDescription(fn (Deployment $record) => "Starts {$record->image} again with the current environment. "
                        .'Database migrations made since then are not reverted, so the older code must work with the current schema.')
                    ->modalSubmitActionLabel('Roll back')
                    ->action(function (Deployment $record): void {
                        $user = Auth::user();

                        try {
                            $deployment = StartDeployment::run(
                                $this->ownerRecord, DeploymentTrigger::Rollback, $user instanceof User ? $user : null, rollbackTo: $record,
                            );
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Rollback not started')->body($e->getMessage())->send();

                            return;
                        }

                        $this->redirect(DeploymentResource::getUrl('view', ['record' => $deployment]));
                    }),
            ]);
    }
}
