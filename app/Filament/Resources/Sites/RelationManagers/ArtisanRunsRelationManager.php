<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sites\RelationManagers;

use App\Actions\Artisan\StartArtisanRun;
use App\Enums\ArtisanCommand;
use App\Models\ArtisanRun;
use App\Models\Site;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Radio;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * @property Site $ownerRecord
 */
class ArtisanRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'artisanRuns';

    protected static ?string $title = 'Commands';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextEntry::make('output')
                    ->hiddenLabel()
                    ->placeholder('No output.')
                    ->formatStateUsing(fn (string $state) => new HtmlString(
                        '<pre style="max-height: 28rem; overflow: auto; white-space: pre-wrap; word-break: break-all; font-size: .75rem">'.e($state).'</pre>'
                    )),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->poll('3s')
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('No commands yet')
            ->emptyStateDescription('Only the commands in the list can be run; there is no free-text input.')
            ->columns([
                TextColumn::make('command')->fontFamily('mono'),
                TextColumn::make('status')->badge(),
                TextColumn::make('exit_code')->label('Exit')->placeholder('—'),
                TextColumn::make('user.name')->label('By')->placeholder('—'),
                TextColumn::make('created_at')->label('Started')->since()->dateTimeTooltip(),
            ])
            ->headerActions([
                Action::make('run')
                    ->label('Run command')
                    ->icon(Heroicon::OutlinedCommandLine)
                    ->modalDescription('Runs in the web container. Migrate changes the production database and cannot be undone by Mini Forge; queue:restart and horizon:terminate stop workers after their current job.')
                    ->schema([
                        Radio::make('command')
                            ->options(ArtisanCommand::class)
                            ->descriptions(collect(ArtisanCommand::cases())
                                ->filter(fn (ArtisanCommand $command) => $command->warning() !== null)
                                ->mapWithKeys(fn (ArtisanCommand $command) => [$command->value => $command->warning()])
                                ->all())
                            ->required(),
                    ])
                    ->modalSubmitActionLabel('Run')
                    ->action(function (array $data): void {
                        $user = Auth::user();
                        $command = $data['command'] instanceof ArtisanCommand ? $data['command'] : ArtisanCommand::from($data['command']);

                        try {
                            StartArtisanRun::run($this->ownerRecord, $command, $user instanceof User ? $user : null);
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Command not started')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Output')
                    ->modalHeading(fn (ArtisanRun $record) => $record->command->getLabel())
                    ->modalWidth('4xl'),
            ]);
    }
}
