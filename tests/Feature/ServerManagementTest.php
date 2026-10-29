<?php

declare(strict_types=1);

use App\Actions\Servers\RemoveServer;
use App\Enums\ProvisioningStatus;
use App\Filament\Resources\Servers\Pages\CreateServer;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Filament\Resources\Servers\Pages\ListServers;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use phpseclib4\Crypt\EC;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('creates a server with a generated key pair', function () {
    Livewire::test(CreateServer::class)
        ->fillForm([
            'name' => 'hetzner-1',
            'host' => '203.0.113.10',
            'ssh_port' => 2222,
            'ssh_user' => 'root',
            'key_source' => 'generate',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $server = Server::sole();

    expect($server->name)->toBe('hetzner-1')
        ->and($server->ssh_port)->toBe(2222)
        ->and($server->provisioning_status)->toBe(ProvisioningStatus::Pending)
        ->and($server->ssh_private_key)->toContain('OPENSSH PRIVATE KEY')
        ->and($server->publicKey())->toStartWith('ssh-ed25519 ');
});

it('creates a server with a pasted key and passphrase', function () {
    $key = EC::createKey('Ed25519')->withPassword('secret')->toString('OpenSSH');

    Livewire::test(CreateServer::class)
        ->fillForm([
            'name' => 'vps',
            'host' => 'vps.example.com',
            'ssh_port' => 22,
            'ssh_user' => 'deploy',
            'key_source' => 'paste',
            'ssh_private_key' => $key,
            'ssh_key_passphrase' => 'secret',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Server::sole()->publicKey())->toStartWith('ssh-ed25519 ');
});

it('rejects an unreadable key or a wrong passphrase', function (string $key, ?string $passphrase) {
    Livewire::test(CreateServer::class)
        ->fillForm([
            'name' => 'vps',
            'host' => '203.0.113.10',
            'key_source' => 'paste',
            'ssh_private_key' => $key,
            'ssh_key_passphrase' => $passphrase,
        ])
        ->call('create')
        ->assertHasFormErrors(['ssh_private_key']);

    expect(Server::count())->toBe(0);
})->with([
    'garbage' => ['not a key', null],
    'wrong passphrase' => [fn () => EC::createKey('Ed25519')->withPassword('right')->toString('OpenSSH'), 'wrong'],
]);

it('validates host, port and user', function () {
    Livewire::test(CreateServer::class)
        ->fillForm([
            'name' => 'vps',
            'host' => 'evil.com; rm -rf /',
            'ssh_port' => 70000,
            'ssh_user' => 'root$(id)',
        ])
        ->call('create')
        ->assertHasFormErrors(['host', 'ssh_port', 'ssh_user']);
});

it('rejects the same host and port twice', function () {
    Server::factory()->create(['host' => '203.0.113.10', 'ssh_port' => 22]);

    Livewire::test(CreateServer::class)
        ->fillForm(['name' => 'again', 'host' => '203.0.113.10', 'ssh_port' => 22])
        ->call('create')
        ->assertHasFormErrors(['host']);
});

it('stores the private key and passphrase encrypted', function () {
    $server = Server::factory()->create(['ssh_key_passphrase' => 'hunter2']);

    $row = DB::table('servers')->find($server->id);

    expect($row->ssh_private_key)->not->toContain('PRIVATE KEY')
        ->and($row->ssh_key_passphrase)->not->toContain('hunter2')
        ->and($server->toArray())->not->toHaveKeys(['ssh_private_key', 'ssh_key_passphrase']);
});

it('never shows the private key in the UI', function () {
    $server = Server::factory()->create();

    $this->get("/servers/{$server->id}")
        ->assertOk()
        ->assertSee($server->publicKey())
        ->assertDontSee('PRIVATE KEY');

    $this->get("/servers/{$server->id}/edit")
        ->assertOk()
        ->assertDontSee('PRIVATE KEY');
});

it('keeps the stored key when the key field is left blank', function () {
    $server = Server::factory()->create();
    $key = $server->ssh_private_key;

    Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
        ->fillForm(['name' => 'renamed', 'ssh_private_key' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($server->refresh()->name)->toBe('renamed')
        ->and($server->ssh_private_key)->toBe($key);
});

it('forgets the pinned host key when the address changes', function () {
    $server = Server::factory()->reachable()->create(['ssh_host_key_fingerprint' => 'SHA256:abc']);

    Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
        ->fillForm(['host' => '198.51.100.7'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($server->refresh()->ssh_host_key_fingerprint)->toBeNull()
        ->and($server->last_connected_at)->toBeNull();
});

it('keeps the pinned host key on other edits', function () {
    $server = Server::factory()->create(['ssh_host_key_fingerprint' => 'SHA256:abc']);

    Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
        ->fillForm(['name' => 'renamed'])
        ->call('save');

    expect($server->refresh()->ssh_host_key_fingerprint)->toBe('SHA256:abc');
});

it('removes a server from Mini Forge', function () {
    $server = Server::factory()->create();

    Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
        ->callAction('remove');

    expect(Server::count())->toBe(0);
});

it('refuses to remove a server that still has sites', function () {
    $site = Site::factory()->create();

    expect(fn () => RemoveServer::run($site->server))->toThrow(RuntimeException::class);
    expect(Server::count())->toBe(1);
});

it('lists servers with their reachability', function () {
    $servers = Server::factory()->count(2)->sequence(
        ['last_connected_at' => now()],
        ['last_connected_at' => now()->subHour()],
    )->create();

    Livewire::test(ListServers::class)
        ->assertCanSeeTableRecords($servers);

    expect($servers[0]->isReachable())->toBeTrue()
        ->and($servers[1]->isReachable())->toBeFalse();
});
