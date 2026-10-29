<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sites\Schemas;

use App\Enums\ServiceRole;
use App\Livewire\SiteContainers;
use App\Models\Site;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SiteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Site')
                    ->columns(3)
                    ->components([
                        TextEntry::make('domain')
                            ->url(fn (Site $record) => 'https://'.$record->domain, shouldOpenInNewTab: true),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('server.name')
                            ->label('Server'),
                        TextEntry::make('current_image')
                            ->label('Running image')
                            ->fontFamily('mono')
                            ->placeholder('Not deployed yet')
                            ->columnSpan(2),
                        TextEntry::make('php_version')->label('PHP'),
                        TextEntry::make('repository_url')->label('Repository')->fontFamily('mono'),
                        TextEntry::make('branch')->fontFamily('mono'),
                        TextEntry::make('remote_path')
                            ->label('On the server')
                            ->state(fn (Site $record) => $record->remotePath())
                            ->fontFamily('mono'),
                        IconEntry::make('worker')
                            ->label('Queue worker')
                            ->state(fn (Site $record) => $record->serviceEnabled(ServiceRole::Worker))
                            ->boolean(),
                        IconEntry::make('scheduler')
                            ->label('Scheduler')
                            ->state(fn (Site $record) => $record->serviceEnabled(ServiceRole::Scheduler))
                            ->boolean(),
                        TextEntry::make('database')
                            ->state(fn (Site $record) => $record->databaseName().' · Redis db '.$record->redis_database)
                            ->fontFamily('mono'),
                    ]),

                Livewire::make(SiteContainers::class, fn (Site $record) => ['site' => $record])
                    ->key('site-containers'),
            ]);
    }
}
