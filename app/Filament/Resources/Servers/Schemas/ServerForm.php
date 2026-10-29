<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servers\Schemas;

use App\Rules\Hostname;
use App\Rules\PrivateKey;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class ServerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Connection')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(64)
                            ->unique(ignoreRecord: true),
                        TextInput::make('host')
                            ->label('Host / IP')
                            ->required()
                            ->maxLength(253)
                            ->rule(new Hostname(allowIp: true))
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('ssh_port', $get('ssh_port')),
                            ),
                        TextInput::make('ssh_port')
                            ->label('SSH port')
                            ->integer()
                            ->required()
                            ->default(22)
                            ->minValue(1)
                            ->maxValue(65535),
                        TextInput::make('ssh_user')
                            ->label('SSH user')
                            ->required()
                            ->default('root')
                            ->maxLength(32)
                            ->regex('/^[a-z_][a-z0-9_-]*$/')
                            ->helperText('Provisioning needs root, or a user with passwordless sudo.'),
                        TextInput::make('acme_email')
                            ->label('Let\'s Encrypt email')
                            ->email()
                            ->maxLength(255)
                            ->helperText('Used by Traefik for HTTPS certificates. Required before provisioning.'),
                    ]),

                Section::make('SSH key')
                    ->components([
                        Radio::make('key_source')
                            ->hiddenLabel()
                            ->options([
                                'generate' => 'Generate a new key pair',
                                'paste' => 'Use an existing private key',
                            ])
                            ->default('generate')
                            ->live()
                            ->dehydrated(false)
                            ->visibleOn('create'),
                        Textarea::make('ssh_private_key')
                            ->label('Private key')
                            ->rows(6)
                            ->extraInputAttributes(['class' => 'font-mono text-xs'])
                            ->required(fn (string $operation, Get $get) => $operation === 'create' && $get('key_source') === 'paste')
                            ->rule(fn (Get $get) => new PrivateKey($get('ssh_key_passphrase')))
                            ->visible(fn (string $operation, Get $get) => $operation === 'edit' || $get('key_source') === 'paste')
                            ->helperText(fn (string $operation) => $operation === 'edit'
                                ? 'Leave blank to keep the stored key. Stored encrypted; never shown again.'
                                : 'Stored encrypted; never shown again.'),
                        TextInput::make('ssh_key_passphrase')
                            ->label('Key passphrase')
                            ->password()
                            ->revealable()
                            ->visible(fn (string $operation, Get $get) => $operation === 'edit' || $get('key_source') === 'paste'),
                    ]),
            ]);
    }
}
