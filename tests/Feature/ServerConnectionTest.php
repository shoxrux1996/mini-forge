<?php

declare(strict_types=1);

use App\Actions\Servers\ConnectToServer;
use App\Actions\Servers\ForgetHostKey;
use App\Actions\Servers\InspectServer;
use App\Actions\Servers\TestConnection;
use App\Contracts\ServerConnector;
use App\Exceptions\HostKeyMismatch;
use App\Exceptions\RemoteCommandFailed;
use App\Exceptions\ServerConnectionFailed;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Models\Server;
use App\Models\User;
use Livewire\Livewire;
use Tests\Fakes\FakeConnection;
use Tests\Fakes\FakeConnector;

beforeEach(function () {
    $this->connector = new FakeConnector(new FakeConnection('SHA256:first'));
    $this->app->instance(ServerConnector::class, $this->connector);
});

it('pins the host key on the first connection', function () {
    $server = Server::factory()->create();

    ConnectToServer::run($server);

    expect($server->refresh()->ssh_host_key_fingerprint)->toBe('SHA256:first')
        ->and($server->last_connected_at)->not->toBeNull()
        ->and($server->isReachable())->toBeTrue();
});

it('refuses a server whose host key changed', function () {
    $server = Server::factory()->create(['ssh_host_key_fingerprint' => 'SHA256:first']);
    $this->connector->connection->fingerprint = 'SHA256:attacker';

    expect(fn () => ConnectToServer::run($server))->toThrow(HostKeyMismatch::class);

    expect($server->refresh()->ssh_host_key_fingerprint)->toBe('SHA256:first')
        ->and($server->last_connected_at)->toBeNull()
        ->and($server->last_error)->toContain('host key changed');
});

it('connects again after the host key is forgotten', function () {
    $server = Server::factory()->create(['ssh_host_key_fingerprint' => 'SHA256:old']);

    ForgetHostKey::run($server);
    ConnectToServer::run($server);

    expect($server->refresh()->ssh_host_key_fingerprint)->toBe('SHA256:first');
});

it('records why a server is unreachable', function () {
    $server = Server::factory()->create();
    $this->connector->unreachable = 'Connection timed out';

    expect(fn () => ConnectToServer::run($server))->toThrow(ServerConnectionFailed::class);
    expect($server->refresh()->last_error)->toBe('Connection timed out');
});

it('tests the connection and root access', function () {
    $server = Server::factory()->create(['ssh_user' => 'deploy']);
    $this->connector->connection->on('/^id -un$/', "root\n");

    expect(TestConnection::run($server))->toContain('Connected as deploy; commands run as root')
        ->and($this->connector->connection->disconnected)->toBeTrue();
});

it('reports missing passwordless sudo', function () {
    $server = Server::factory()->create(['ssh_user' => 'deploy']);
    $this->connector->connection->fail('/^id -un$/', 'sudo: a password is required');

    expect(fn () => TestConnection::run($server))
        ->toThrow(RemoteCommandFailed::class, 'sudo: a password is required');
});

it('saves inspected server facts', function () {
    $server = Server::factory()->create();
    $this->connector->connection->on('/os-release/', implode("\n", [
        'os_name=Ubuntu',
        'os_version=24.04',
        'architecture=x86_64',
        'cpu_cores=1',
        'memory_mb=961',
        'disk_gb=20',
        'uptime_seconds=86400',
        'docker_version=29.0.1',
        'compose_version=2.40.3',
    ]));

    InspectServer::run($server);

    expect($server->refresh())
        ->os_name->toBe('Ubuntu')
        ->os_version->toBe('24.04')
        ->cpu_cores->toBe(1)
        ->memory_mb->toBe(961)
        ->disk_gb->toBe(20)
        ->uptime_seconds->toBe(86400)
        ->docker_version->toBe('29.0.1')
        ->compose_version->toBe('2.40.3')
        ->inspected_at->not->toBeNull();
});

it('treats missing Docker as not installed', function () {
    expect(InspectServer::parse("os_name=Ubuntu\ndocker_version=\ncompose_version=\ngarbage line"))
        ->toBe(['os_name' => 'Ubuntu']);
});

it('runs the connection test from the server page', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->create();
    $this->connector->connection->on('/^id -un$/', 'root');

    Livewire::test(ViewServer::class, ['record' => $server->getRouteKey()])
        ->callAction('testConnection')
        ->assertNotified('Connection works');

    $this->connector->unreachable = 'No route to host';

    Livewire::test(ViewServer::class, ['record' => $server->getRouteKey()])
        ->callAction('testConnection')
        ->assertNotified('Failed');
});
