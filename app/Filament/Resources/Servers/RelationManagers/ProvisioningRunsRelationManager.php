<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servers\RelationManagers;

use App\Enums\RunStatus;
use App\Models\ServerProvisioningRun;
use Carbon\CarbonInterface;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class ProvisioningRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'provisioningRuns';

    protected static ?string $title = 'Provisioning logs';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextEntry::make('error')
                    ->color('danger')
                    ->visible(fn (ServerProvisioningRun $record) => filled($record->error)),
                RepeatableEntry::make('steps')
                    ->hiddenLabel()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('label')->hiddenLabel()->weight('medium')->columnSpan(3),
                        TextEntry::make('status')
                            ->hiddenLabel()
                            ->badge()
                            ->formatStateUsing(fn (string $state) => RunStatus::from($state)->getLabel())
                            ->color(fn (string $state) => RunStatus::from($state)->getColor()),
                        TextEntry::make('output')
                            ->hiddenLabel()
                            ->formatStateUsing(fn (string $state) => new HtmlString(
                                '<pre class="max-h-80 overflow-auto whitespace-pre-wrap break-all font-mono text-xs">'.e($state).'</pre>'
                            ))
                            ->columnSpanFull()
                            ->visible(fn (?string $state) => filled($state)),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->poll('3s')
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Started')
                    ->since()
                    ->dateTimeTooltip(),
                TextColumn::make('status')->badge(),
                TextColumn::make('progress')
                    ->state(function (ServerProvisioningRun $record): string {
                        $steps = collect($record->steps);
                        $current = $steps->firstWhere('status', RunStatus::Running->value)
                            ?? $steps->firstWhere('status', RunStatus::Failed->value);

                        return $current['label'] ?? $steps->whereIn('status', ['succeeded', 'skipped'])->count().'/'.$steps->count().' steps';
                    }),
                TextColumn::make('duration')
                    ->state(fn (ServerProvisioningRun $record) => $record->started_at && $record->finished_at
                        ? $record->started_at->diffForHumans($record->finished_at, CarbonInterface::DIFF_ABSOLUTE, short: true, parts: 2)
                        : null)
                    ->placeholder('—'),
                TextColumn::make('error')
                    ->limit(80)
                    ->color('danger')
                    ->placeholder('—'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Steps')
                    ->modalHeading('Provisioning steps')
                    ->modalWidth('4xl'),
            ]);
    }
}
