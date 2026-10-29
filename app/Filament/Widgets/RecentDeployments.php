<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Deployment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentDeployments extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Deployment::query()->with('site')->latest()->limit(10))
            ->paginated(false)
            ->emptyStateHeading('No deployments yet')
            ->columns([
                TextColumn::make('site.name'),
                TextColumn::make('status')->badge(),
                TextColumn::make('trigger'),
                TextColumn::make('commit_sha')
                    ->label('Commit')
                    ->formatStateUsing(fn (?string $state) => $state ? substr($state, 0, 7) : null)
                    ->fontFamily('mono')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Started')
                    ->since()
                    ->dateTimeTooltip(),
            ]);
    }
}
