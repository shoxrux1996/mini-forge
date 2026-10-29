<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servers\Tables;

use App\Enums\ProvisioningStatus;
use App\Models\Server;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ServersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('sites'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('host')
                    ->searchable()
                    ->fontFamily('mono'),
                TextColumn::make('provisioning_status')
                    ->label('Provisioning')
                    ->badge(),
                IconColumn::make('reachable')
                    ->state(fn (Server $record) => $record->isReachable())
                    ->boolean()
                    ->tooltip(fn (Server $record) => $record->last_connected_at
                        ? 'Last connected '.$record->last_connected_at->diffForHumans()
                        : 'Never connected'),
                TextColumn::make('sites_count')
                    ->label('Sites')
                    ->sortable(),
                TextColumn::make('docker_version')
                    ->label('Docker')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('provisioning_status')
                    ->label('Provisioning')
                    ->options(ProvisioningStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('name');
    }
}
