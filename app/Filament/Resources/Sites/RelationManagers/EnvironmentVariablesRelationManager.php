<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sites\RelationManagers;

use App\Filament\Resources\Sites\Pages\ViewSite;
use App\Models\Site;
use App\Models\SiteEnvironmentVariable;
use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * @property Site $ownerRecord
 */
class EnvironmentVariablesRelationManager extends RelationManager
{
    protected static string $relationship = 'environmentVariables';

    protected static ?string $title = 'Environment';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('key')
                    ->required()
                    ->maxLength(128)
                    ->regex(SiteEnvironmentVariable::KEY_PATTERN)
                    ->validationMessages(['regex' => 'Use capital letters, digits and underscores, like MAIL_HOST.'])
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule) => $rule->where('site_id', $this->ownerRecord->id),
                    )
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, callable $set, string $operation): void {
                        if ($operation === 'create' && $state !== null) {
                            $set('is_sensitive', SiteEnvironmentVariable::looksSensitive($state));
                        }
                    }),
                Textarea::make('value')
                    ->rows(2)
                    ->maxLength(65535)
                    ->rule('not_regex:/\x00/')
                    ->hidden(fn (Get $get) => (bool) $get('is_sensitive')),
                TextInput::make('value')
                    ->password()
                    ->revealable()
                    ->maxLength(65535)
                    ->rule('not_regex:/\x00/')
                    ->visible(fn (Get $get) => (bool) $get('is_sensitive')),
                Toggle::make('is_sensitive')
                    ->label('Sensitive')
                    ->helperText('Hidden in lists. Every value is stored encrypted either way.')
                    ->live(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('key')
            ->defaultSort('key')
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->description('Changes are saved in Mini Forge and reach the server on the next apply or deploy.')
            ->columns([
                TextColumn::make('key')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('value')
                    ->state(fn (SiteEnvironmentVariable $record) => $record->is_sensitive ? '••••••••' : $record->value)
                    ->fontFamily('mono')
                    ->limit(60)
                    ->placeholder('(empty)'),
                IconColumn::make('is_sensitive')
                    ->label('Sensitive')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedLockClosed)
                    ->falseIcon(Heroicon::OutlinedLockOpen),
            ])
            ->headerActions([
                CreateAction::make()->label('Add variable'),
                $this->importAction(),
                ViewSite::applyAction()->record($this->ownerRecord),
            ])
            ->recordActions([
                EditAction::make()
                    // `value` is hidden from serialization; load it for editing only.
                    ->mutateRecordDataUsing(fn (array $data, SiteEnvironmentVariable $record) => [...$data, 'value' => $record->value]),
                DeleteAction::make(),
            ]);
    }

    private function importAction(): Action
    {
        return Action::make('import')
            ->label('Import .env')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->modalDescription('Paste a .env file. Existing keys are overwritten; keys not in the paste are kept.')
            ->schema([
                Textarea::make('contents')
                    ->hiddenLabel()
                    ->rows(14)
                    ->required()
                    ->extraInputAttributes(['class' => 'font-mono text-xs']),
            ])
            ->action(function (array $data): void {
                try {
                    $parsed = Dotenv::parse($data['contents']);
                } catch (InvalidFileException $e) {
                    Notification::make()->danger()->title('Could not read that .env')->body($e->getMessage())->send();

                    return;
                }

                $invalid = array_filter(array_keys($parsed), fn (string $key) => preg_match(SiteEnvironmentVariable::KEY_PATTERN, $key) !== 1);

                if ($invalid !== []) {
                    Notification::make()->danger()->title('Invalid names: '.implode(', ', $invalid))->send();

                    return;
                }

                foreach ($parsed as $key => $value) {
                    $variable = $this->ownerRecord->environmentVariables()->firstOrNew(['key' => $key]);
                    $variable->value = $value;
                    $variable->is_sensitive = $variable->exists ? $variable->is_sensitive : SiteEnvironmentVariable::looksSensitive($key);
                    $variable->save();
                }

                Notification::make()->success()->title('Imported '.count($parsed).' variables')->send();
            });
    }
}
