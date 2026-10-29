<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servers\RelationManagers;

use App\Filament\Resources\Sites\SiteResource;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SitesRelationManager extends RelationManager
{
    protected static string $relationship = 'sites';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Site $record) => SiteResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('domain'),
                TextColumn::make('status')->badge(),
                TextColumn::make('current_image')
                    ->label('Image')
                    ->formatStateUsing(fn (?string $state) => $state ? str($state)->afterLast(':')->limit(12) : null)
                    ->fontFamily('mono')
                    ->placeholder('Not deployed'),
            ])
            ->headerActions([
                Action::make('create')
                    ->label('New site')
                    ->url(SiteResource::getUrl('create')),
            ]);
    }
}
