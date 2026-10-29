<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servers\Schemas;

use App\Models\Server;
use Carbon\CarbonInterval;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ServerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Connection')
                    ->columns(3)
                    ->components([
                        TextEntry::make('address')
                            ->state(fn (Server $record) => "{$record->ssh_user}@{$record->host}:{$record->ssh_port}")
                            ->copyable(),
                        TextEntry::make('provisioning_status')
                            ->label('Provisioning')
                            ->badge(),
                        TextEntry::make('acme_email')
                            ->label('Let\'s Encrypt email')
                            ->placeholder('Not set'),
                        TextEntry::make('last_connected_at')
                            ->label('Last successful connection')
                            ->since()
                            ->dateTimeTooltip()
                            ->placeholder('Never'),
                        TextEntry::make('ssh_host_key_fingerprint')
                            ->label('Pinned host key')
                            ->fontFamily('mono')
                            ->placeholder('Pinned on first connection')
                            ->columnSpan(2),
                        TextEntry::make('last_error')
                            ->color('danger')
                            ->placeholder('None')
                            ->columnSpanFull()
                            ->visible(fn (Server $record) => filled($record->last_error)),
                    ]),

                Section::make('Public key')
                    ->description('Add this line to ~/.ssh/authorized_keys for the SSH user on the server.')
                    ->components([
                        TextEntry::make('public_key')
                            ->hiddenLabel()
                            ->state(fn (Server $record) => $record->publicKey())
                            ->fontFamily('mono')
                            ->copyable(),
                    ]),

                Section::make('System')
                    ->description(fn (Server $record) => $record->inspected_at
                        ? 'As of '.$record->inspected_at->diffForHumans().'. Not live.'
                        : 'Not inspected yet.')
                    ->columns(4)
                    ->components([
                        TextEntry::make('os')
                            ->label('OS')
                            ->state(fn (Server $record) => trim("{$record->os_name} {$record->os_version}") ?: null)
                            ->placeholder('Unknown'),
                        TextEntry::make('architecture')->placeholder('Unknown'),
                        TextEntry::make('cpu_cores')->label('CPU cores')->placeholder('Unknown'),
                        TextEntry::make('memory_mb')->label('Memory')->suffix(' MB')->placeholder('Unknown'),
                        TextEntry::make('disk_gb')->label('Disk')->suffix(' GB')->placeholder('Unknown'),
                        TextEntry::make('uptime_seconds')
                            ->label('Uptime')
                            ->formatStateUsing(fn (int $state) => CarbonInterval::seconds($state)->cascade()->forHumans(short: true, parts: 2))
                            ->placeholder('Unknown'),
                        TextEntry::make('docker_version')->placeholder('Not installed'),
                        TextEntry::make('compose_version')->placeholder('Not installed'),
                    ]),
            ]);
    }
}
