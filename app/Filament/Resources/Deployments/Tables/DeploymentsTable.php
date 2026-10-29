<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deployments\Tables;

use App\Enums\RunStatus;
use App\Filament\Resources\Deployments\DeploymentResource;
use App\Models\Deployment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DeploymentsTable
{
    /**
     * @param  bool  $withSite  false inside a site's own page
     */
    public static function configure(Table $table, bool $withSite = true): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['site', 'user']))
            ->poll('3s')
            ->defaultSort('id', 'desc')
            ->columns(array_values(array_filter([
                TextColumn::make('id')->label('#')->sortable(),
                $withSite ? TextColumn::make('site.domain')->label('Site')->searchable() : null,
                TextColumn::make('status')->badge(),
                TextColumn::make('trigger')->badge()->color('gray'),
                TextColumn::make('image')
                    ->label('Tag')
                    ->formatStateUsing(fn (?string $state) => $state ? str($state)->afterLast(':')->toString() : null)
                    ->fontFamily('mono')
                    ->placeholder('—'),
                TextColumn::make('error')
                    ->limit(60)
                    ->color('danger')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('user.name')->label('By')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('duration')
                    ->state(fn (Deployment $record) => ($seconds = $record->durationInSeconds()) === null
                        ? null
                        : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60))
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Started')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])))
            ->filters([
                SelectFilter::make('status')->options(RunStatus::class),
            ])
            ->recordUrl(fn (Deployment $record) => DeploymentResource::getUrl('view', ['record' => $record]));
    }
}
