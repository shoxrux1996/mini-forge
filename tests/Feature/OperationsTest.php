<?php

declare(strict_types=1);

use App\Actions\Artisan\RunArtisanCommand;
use App\Actions\Artisan\StartArtisanRun;
use App\Actions\Servers\ReadServerMetrics;
use App\Actions\Sites\CreateSite;
use App\Actions\Sites\InspectSiteContainer;
use App\Actions\Sites\ReadSiteLogs;
use App\Actions\Sites\SiteOperation;
use App\Contracts\ServerConnector;
use App\Data\CommandResult;
use App\Enums\ArtisanCommand;
use App\Enums\ProvisioningStatus;
use App\Enums\RunStatus;
use App\Enums\ServiceRole;
use App\Enums\SiteStatus;
use App\Filament\Resources\Sites\Pages\SiteLogs;
use App\Filament\Resources\Sites\Pages\ViewSite;
use App\Filament\Resources\Sites\RelationManagers\ArtisanRunsRelationManager;
use App\Infrastructure\Docker\ComposeManager;
use App\Jobs\RunArtisanCommandJob;
use App\Livewire\ServerMetricsPanel;
use App\Models\Server;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fakes\FakeConnection;
use Tests\Fakes\FakeConnector;

beforeEach(function () {
    $this->connection = new FakeConnection;
    $this->connector = new FakeConnector($this->connection);
    $this->app->instance(ServerConnector::class, $this->connector);
    $this->server = Server::factory()->create();
    $this->server->forceFill(['provisioning_status' => ProvisioningStatus::Ready])->save();
    $this->site = CreateSite::run([
        'server_id' => $this->server->id, 'name' => 'Shop', 'domain' => 'shop.example.com',
        'repository_url' => 'git@github.com:acme/shop.git', 'image_repository' => 'ghcr.io/acme/shop',
    ]);
    $this->site->markStatus(SiteStatus::Running);
});

const METRICS = <<<'TEXT'
cpu_percent=12
load=0.52 0.48 0.40
memory_total_mb=1000
memory_used_mb=950
swap_total_mb=0
swap_used_mb=0
disk_total_gb=50
disk_used_gb=20
container={"Name":"web-1","CPUPerc":"1.20%","MemUsage":"120MiB / 512MiB","MemPerc":"23.44%","PIDs":"12"}
container={"Name":"a-worker","CPUPerc":"0.10%","MemUsage":"80MiB / 384MiB","MemPerc":"20.83%","PIDs":"3"}
container=not json
TEXT;

it('parses server metrics', function () {
    $metrics = ReadServerMetrics::parse(METRICS);

    expect($metrics['cpu_percent'])->toBe(12)
        ->and($metrics['load'])->toBe('0.52 0.48 0.40')
        ->and($metrics['memory_used_mb'])->toBe(950)
        ->and($metrics['disk_total_gb'])->toBe(50)
        ->and(array_column($metrics['containers'], 'name'))->toBe(['a-worker', 'web-1']);
});

it('leaves unreadable metrics empty instead of inventing them', function () {
    expect(ReadServerMetrics::parse('cpu_percent=abc'))
        ->cpu_percent->toBeNull()->memory_total_mb->toBeNull()->containers->toBe([]);
});

it('shows live metrics with a text warning, and reports failures', function () {
    $this->connection->on('/proc\/stat/', METRICS);

    Livewire::test(ServerMetricsPanel::class, ['server' => $this->server])
        ->call('load')
        ->assertSee('95%')
        ->assertSee('Critical')
        ->assertSee('950 of 1000 MB')
        ->assertSee('None configured')
        ->assertSee('a-worker');

    $this->connector->unreachable = 'Connection refused';

    Livewire::test(ServerMetricsPanel::class, ['server' => $this->server])
        ->call('load')
        ->assertSee('Connection refused')
        ->assertDontSee('Critical');
});

it('reads logs of one service, strips colours and masks secrets', function () {
    $password = $this->site->environmentVariables()->where('key', 'DB_PASSWORD')->value('value');
    $this->connection->on('/compose .*logs/', "\e[31mERROR\e[0m could not connect with {$password}\n");

    $output = ReadSiteLogs::run($this->site, ServiceRole::Worker, 500);

    expect($output)->toBe("ERROR could not connect with [redacted]\n")
        ->and($this->connection->ran('/logs --no-color --no-log-prefix --timestamps --tail 500 worker 2>&1 \| tail -c 262144/'))->toBeTrue();
});

it('filters logs and ignores a service or line count it does not offer', function () {
    $this->actingAs(User::factory()->create());
    $this->connection->on('/compose .*logs/', "first line\nGET /up 200\n");

    Livewire::test(SiteLogs::class, ['record' => $this->site->getRouteKey()])
        ->call('load')
        ->assertSee('first line')
        ->set('search', 'get /UP')
        ->assertSee('GET /up 200')
        ->assertDontSee('first line')
        ->set('search', '')
        ->set('service', 'web; rm -rf /')
        ->set('lines', 99999);

    expect($this->connection->ran('/rm -rf/'))->toBeFalse()
        ->and($this->connection->ran('/--tail 99999/'))->toBeFalse();
});

it('shows only safe fields of a container', function () {
    $inspection = json_encode([[
        'Name' => '/shop-web-1',
        'RestartCount' => 2,
        'Config' => ['Image' => 'ghcr.io/acme/shop:1', 'Cmd' => ['frankenphp', 'run'], 'Env' => ['SECRET_THING=do-not-show']],
        'State' => ['Status' => 'running', 'ExitCode' => 0, 'OOMKilled' => false, 'StartedAt' => '2026-11-01T10:00:00Z',
            'Health' => ['Status' => 'healthy', 'Log' => [['Output' => 'old'], ['Output' => 'ok']]]],
        'HostConfig' => ['Memory' => 536870912],
        'NetworkSettings' => ['Networks' => ['mini-forge' => [], 'default' => []]],
        'Mounts' => [['Destination' => '/app/.env', 'RW' => false], ['Destination' => '/app/storage', 'RW' => true]],
    ]]);
    $this->connection->on('/docker inspect/', $inspection);

    $details = InspectSiteContainer::run($this->site, ServiceRole::Web);

    expect($details)->toMatchArray([
        'Name' => 'shop-web-1', 'Health' => 'healthy', 'Restarts' => '2', 'Memory limit' => '512 MB',
        'Exit code' => '0', 'Out of memory' => 'No', 'Last health check' => 'ok',
        'Networks' => 'mini-forge, default', 'Mounts' => '/app/.env (read-only), /app/storage',
    ])->and(implode(' ', $details))->not->toContain('do-not-show');
});

it('rejects an empty inspection', function () {
    expect(fn () => ComposeManager::parseInspection('[]'))->toThrow(RuntimeException::class, 'no container details');
});

it('allows only the listed Artisan commands', function () {
    expect(array_map(fn (ArtisanCommand $c) => $c->getLabel(), ArtisanCommand::cases()))->toBe([
        'migrate --force --no-interaction', 'migrate:status --no-interaction', 'optimize --no-interaction',
        'optimize:clear --no-interaction', 'config:cache --no-interaction', 'route:cache --no-interaction',
        'view:cache --no-interaction', 'queue:restart --no-interaction', 'horizon:terminate --no-interaction',
    ]);

    $this->actingAs(User::factory()->create());
    Queue::fake();

    Livewire::test(ArtisanRunsRelationManager::class, ['ownerRecord' => $this->site, 'pageClass' => ViewSite::class])
        ->callAction(TestAction::make('run')->table(), ['command' => 'tinker'])
        ->assertHasActionErrors(['command']);

    expect($this->site->artisanRuns()->count())->toBe(0);
});

it('queues a command from the Commands tab', function () {
    $this->actingAs($user = User::factory()->create());
    Queue::fake();

    Livewire::test(ArtisanRunsRelationManager::class, ['ownerRecord' => $this->site, 'pageClass' => ViewSite::class])
        ->callAction(TestAction::make('run')->table(), ['command' => 'migrate'])
        ->assertHasNoActionErrors();

    $run = $this->site->artisanRuns()->sole();

    expect($run->command)->toBe(ArtisanCommand::Migrate)
        ->and($run->status)->toBe(RunStatus::Pending)
        ->and($run->user_id)->toBe($user->id);

    Queue::assertPushed(RunArtisanCommandJob::class);
});

it('runs a command in the web container and stores redacted output', function () {
    $password = $this->site->environmentVariables()->where('key', 'DB_PASSWORD')->value('value');
    $this->connection->on('/artisan/', "Migrated with {$password}");
    Queue::fake();

    $run = RunArtisanCommand::run(StartArtisanRun::run($this->site, ArtisanCommand::Migrate));

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->exit_code)->toBe(0)
        ->and($run->output)->toBe('Migrated with [redacted]')
        ->and($this->connection->ran("/exec -T web 'php' 'artisan' 'migrate' '--force' '--no-interaction'/"))->toBeTrue();
});

it('records a failing command with its exit code', function () {
    $this->connection->on('/artisan/', new CommandResult(1, '', 'Command "horizon:terminate" is not defined.'));
    Queue::fake();

    $run = RunArtisanCommand::run(StartArtisanRun::run($this->site, ArtisanCommand::HorizonTerminate));

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->exit_code)->toBe(1)
        ->and($run->output)->toContain('is not defined');
});

it('does not run a command during another operation on the site', function () {
    Queue::fake();
    $run = StartArtisanRun::run($this->site, ArtisanCommand::Migrate);
    $lock = Cache::lock(SiteOperation::lockName($this->site), 60);
    $lock->get();

    RunArtisanCommand::run($run);
    $lock->release();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->output)->toContain('Another operation')
        ->and($this->connection->ran('/artisan/'))->toBeFalse();
});

it('refuses commands on a site that is not running', function () {
    $this->site->markStatus(SiteStatus::Stopped);

    expect(fn () => StartArtisanRun::run($this->site, ArtisanCommand::Optimize))
        ->toThrow(RuntimeException::class, 'must be running');
});

it('marks a command the worker never finished as failed', function () {
    Queue::fake();
    $run = StartArtisanRun::run($this->site, ArtisanCommand::Migrate);

    (new RunArtisanCommandJob($run))->failed(new RuntimeException('Job timed out'));

    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and($run->output)->toBe('Interrupted: Job timed out');
});
