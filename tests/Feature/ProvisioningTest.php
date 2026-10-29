<?php

declare(strict_types=1);

use App\Actions\Servers\ProvisionServer;
use App\Actions\Servers\StartProvisioning;
use App\Contracts\ServerConnector;
use App\Enums\ProvisioningStatus;
use App\Enums\RunStatus;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Filament\Resources\Servers\RelationManagers\ProvisioningRunsRelationManager;
use App\Jobs\ProvisionServerJob;
use App\Models\Server;
use App\Models\ServerProvisioningRun;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fakes\FakeConnection;
use Tests\Fakes\FakeConnector;

/** A server that answers like a fresh Ubuntu box once Docker is installed. */
function healthyConnection(): FakeConnection
{
    return (new FakeConnection)
        ->on('/os-release/', "os_name=Ubuntu\nos_version=24.04\ndocker_version=29.0.1\ncompose_version=2.40.3")
        ->on("/State.Health.Status}}' mini-forge-traefik mini-forge-postgres/", "/mini-forge-traefik healthy\n/mini-forge-postgres healthy\n/mini-forge-redis healthy\n");
}

function stepStatuses(ServerProvisioningRun $run): array
{
    return collect($run->steps)->pluck('status', 'key')->all();
}

beforeEach(function () {
    $this->connection = healthyConnection();
    $this->app->instance(ServerConnector::class, new FakeConnector($this->connection));
    $this->server = Server::factory()->create(['acme_email' => 'ops@example.com']);
});

it('queues a run with every step pending', function () {
    Queue::fake();

    $run = StartProvisioning::run($this->server);

    Queue::assertPushed(ProvisionServerJob::class, fn ($job) => $job->run->is($run));
    expect($run->status)->toBe(RunStatus::Pending)
        ->and(collect($run->steps)->pluck('status')->unique()->all())->toBe(['pending'])
        ->and($this->server->refresh()->provisioning_status)->toBe(ProvisioningStatus::Provisioning);
});

it('needs a Let\'s Encrypt email first', function () {
    Queue::fake();
    $this->server->update(['acme_email' => null]);

    expect(fn () => StartProvisioning::run($this->server))->toThrow(RuntimeException::class, 'Let\'s Encrypt');
    Queue::assertNothingPushed();
});

it('refuses to start while a run is in progress', function () {
    Queue::fake();
    StartProvisioning::run($this->server);

    expect(fn () => StartProvisioning::run($this->server))->toThrow(RuntimeException::class, 'already being provisioned');
    Queue::assertPushed(ProvisionServerJob::class, 1);
});

it('marks an abandoned run as interrupted and starts a new one', function () {
    Queue::fake();
    $stale = $this->server->provisioningRuns()->create(['status' => RunStatus::Running]);
    $stale->forceFill(['created_at' => now()->subHours(2)])->save();

    StartProvisioning::run($this->server);

    expect($stale->refresh()->status)->toBe(RunStatus::Failed)
        ->and($stale->error)->toContain('Interrupted');
});

it('provisions a fresh server end to end', function () {
    StartProvisioning::run($this->server);

    $run = $this->server->provisioningRuns()->sole();

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and(array_values(array_unique(stepStatuses($run))))->toBe(['succeeded'])
        ->and($this->server->refresh()->provisioning_status)->toBe(ProvisioningStatus::Ready)
        ->and($this->server->docker_version)->toBe('29.0.1')
        ->and($this->server->ssh_host_key_fingerprint)->toBe('SHA256:fake-host-key');

    $compose = $this->connection->uploads['/opt/mini-forge/infrastructure/compose.yaml'];
    expect($compose['mode'])->toBe(0640)
        ->and($compose['contents'])->toContain('ops@example.com');

    expect($this->connection->ran('/apt-get install -y -q docker-ce/'))->toBeTrue()
        ->and($this->connection->ran('/docker compose up -d/'))->toBeTrue()
        ->and($this->connection->ran('/export ROOT=\'\/opt\/mini-forge\'/'))->toBeTrue()
        ->and($this->connection->disconnected)->toBeTrue();
});

it('records skipped steps on an already provisioned server', function () {
    $this->connection
        ->on('/command -v docker/', "SKIPPED: Docker version 29.0.1 is already installed; its configuration is left as is\n")
        ->on('/mini-forge-traefik 2>\/dev\/null/', "SKIPPED: Mini Forge's Traefik already serves ports 80 and 443\n");

    StartProvisioning::run($this->server);

    $run = $this->server->provisioningRuns()->sole();
    $install = collect($run->steps)->firstWhere('key', 'install-docker');

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and(stepStatuses($run)['install-docker'])->toBe('skipped')
        ->and(stepStatuses($run)['check-ports'])->toBe('skipped')
        ->and($install['output'])->toStartWith('Docker version 29.0.1');
});

it('stops at the first failing step and leaves the rest pending', function () {
    $this->connection->fail('/ss -ltnpH/', "Ports 80/443 are in use by another service.\nLISTEN 0 511 0.0.0.0:80 users:((\"nginx\"))");

    StartProvisioning::run($this->server);

    $run = $this->server->provisioningRuns()->sole();
    $statuses = stepStatuses($run);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->error)->toContain('nginx')
        ->and($statuses['check-system'])->toBe('succeeded')
        ->and($statuses['check-ports'])->toBe('failed')
        ->and($statuses['install-docker'])->toBe('pending')
        ->and($this->server->refresh()->provisioning_status)->toBe(ProvisioningStatus::Failed)
        ->and($this->connection->ran('/docker-ce/'))->toBeFalse();
});

it('fails verification when a service is not healthy', function () {
    $this->connection->on("/State.Health.Status}}' mini-forge-traefik mini-forge-postgres/", "/mini-forge-traefik healthy\n/mini-forge-postgres starting\n/mini-forge-redis healthy\n");

    StartProvisioning::run($this->server);

    $run = $this->server->provisioningRuns()->sole();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and(stepStatuses($run)['verify'])->toBe('failed')
        ->and($run->error)->toContain('Not healthy: /mini-forge-postgres starting');
});

it('fails when the server is unreachable', function () {
    $this->app->instance(ServerConnector::class, tap(new FakeConnector, fn ($c) => $c->unreachable = 'Connection refused'));

    StartProvisioning::run($this->server);

    expect($this->server->provisioningRuns()->sole())
        ->status->toBe(RunStatus::Failed)
        ->error->toBe('Connection refused');
});

it('does not run two provisionings of one server at once', function () {
    $lock = Cache::lock("server:{$this->server->id}:provisioning", 60);
    $lock->get();

    $run = $this->server->provisioningRuns()->create(['steps' => ProvisionServer::initialSteps()]);
    ProvisionServer::run($run);

    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and($run->error)->toContain('lock')
        ->and($this->connection->scripts)->toBeEmpty();

    $lock->release();
});

it('marks the run interrupted when the job itself dies', function () {
    $run = $this->server->provisioningRuns()->create(['status' => RunStatus::Running]);

    (new ProvisionServerJob($run))->failed(new RuntimeException('Job timed out'));

    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and($run->error)->toBe('Interrupted: Job timed out')
        ->and($this->server->refresh()->provisioning_status)->toBe(ProvisioningStatus::Failed);
});

it('starts provisioning from the server page', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    Livewire::test(ViewServer::class, ['record' => $this->server->getRouteKey()])
        ->callAction('provision')
        ->assertNotified('Provisioning started');

    Queue::assertPushed(ProvisionServerJob::class);
});

it('logs the full output of a failed step and the cause in the error', function () {
    $noise = collect(range(1, 50))->map(fn ($i) => "Pulling layer {$i}")->implode("\n");
    $this->connection->fail('/docker compose up -d/', $noise."\nError response from daemon: failed to mount overlay");

    StartProvisioning::run($this->server);

    $run = $this->server->provisioningRuns()->sole();
    $step = collect($run->steps)->firstWhere('key', 'start-services');

    expect($run->error)->toContain('failed to mount overlay')
        ->and($run->error)->not->toContain('Pulling layer 20')
        ->and($step['output'])->toContain('Pulling layer 1')
        ->and($step['output'])->toContain('failed to mount overlay');
});

it('shows step output escaped and with its line breaks', function () {
    $this->actingAs(User::factory()->create());
    StartProvisioning::run($this->server);
    $run = $this->server->provisioningRuns()->sole();
    $steps = $run->steps;
    $steps[0]['output'] = "line one\n<script>alert(1)</script>";
    $run->update(['steps' => $steps]);

    Livewire::test(ProvisioningRunsRelationManager::class, ['ownerRecord' => $this->server, 'pageClass' => ViewServer::class])
        ->mountTableAction('view', $run)
        ->assertMountedActionModalSeeHtml("line one\n&lt;script&gt;alert(1)&lt;/script&gt;")
        ->assertMountedActionModalDontSeeHtml('<script>alert(1)</script>');
});
