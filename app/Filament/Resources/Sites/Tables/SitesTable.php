<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sites\Tables;

use App\Enums\SiteStatus;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SitesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('server'))
            ->columns([
                TextColumn::make('domain')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('server.name')
                    ->label('Server')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('current_image')
                    ->label('Image')
                    ->formatStateUsing(fn (?string $state) => $state ? str($state)->afterLast(':')->limit(12) : null)
                    ->fontFamily('mono')
                    ->placeholder('Not deployed'),
            ])
            ->filters([
                SelectFilter::make('status')->options(SiteStatus::class),
                SelectFilter::make('server')->relationship('server', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->label('Settings'),
            ])
            ->defaultSort('domain');
    }
}
