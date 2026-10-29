<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deployments;

use App\Filament\Resources\Deployments\Pages\ListDeployments;
use App\Filament\Resources\Deployments\Pages\ViewDeployment;
use App\Filament\Resources\Deployments\Tables\DeploymentsTable;
use App\Filament\Resources\Sites\SiteResource;
use App\Livewire\DeploymentLog;
use App\Models\Deployment;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DeploymentResource extends Resource
{
    protected static ?string $model = Deployment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(4)
                    ->components([
                        TextEntry::make('site.domain')
                            ->label('Site')
                            ->url(fn (Deployment $record) => SiteResource::getUrl('view', ['record' => $record->site])),
                        TextEntry::make('trigger')->badge()->color('gray'),
                        TextEntry::make('user.name')->label('By')->placeholder('—'),
                        TextEntry::make('created_at')->label('Queued')->dateTime(),
                        TextEntry::make('branch')->fontFamily('mono')->placeholder('—'),
                        TextEntry::make('previous_image')
                            ->label('Replaces')
                            ->fontFamily('mono')
                            ->placeholder('Nothing (first deployment)')
                            ->columnSpan(3),
                    ]),

                Livewire::make(DeploymentLog::class, fn (Deployment $record) => ['deployment' => $record])
                    ->key('deployment-log'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return DeploymentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeployments::route('/'),
            'view' => ViewDeployment::route('/{record}'),
        ];
    }
}
