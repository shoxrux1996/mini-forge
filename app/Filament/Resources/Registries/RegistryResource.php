<?php

declare(strict_types=1);

namespace App\Filament\Resources\Registries;

use App\Filament\Resources\Registries\Pages\ManageRegistries;
use App\Models\Registry;
use App\Rules\Matches;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RegistryResource extends Resource
{
    protected static ?string $model = Registry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(64)
                    ->placeholder('GitHub packages'),
                TextInput::make('host')
                    ->required()
                    ->maxLength(255)
                    ->rule(new Matches(Registry::HOST_PATTERN, 'Use a registry host like ghcr.io, docker.io or localhost:5000.'))
                    ->placeholder('ghcr.io')
                    ->helperText('Must match the first part of the sites\' image repositories (docker.io for Docker Hub).'),
                TextInput::make('username')
                    ->required()
                    ->maxLength(255),
                TextInput::make('password')
                    ->label('Password or token')
                    ->password()
                    ->revealable(false)
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->maxLength(4096)
                    ->helperText(fn (string $operation) => $operation === 'create'
                        ? 'Needs push and pull access. For GHCR, a token with write:packages. Stored encrypted.'
                        : 'Leave blank to keep the stored token.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('host')->fontFamily('mono'),
                TextColumn::make('username'),
                TextColumn::make('sites_count')->counts('sites')->label('Sites'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->before(function (Registry $record, DeleteAction $action): void {
                        if ($record->sites()->exists()) {
                            Notification::make()->danger()->title('Sites still use this registry')->send();
                            $action->cancel();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRegistries::route('/'),
        ];
    }
}
