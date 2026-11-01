<?php

declare(strict_types=1);

use App\Actions\Deployments\DeploySite;
use App\Actions\Deployments\StartDeployment;
use App\Actions\Sites\CreateSite;
use App\Contracts\ServerConnector;
use App\Enums\DeploymentTrigger;
use App\Enums\ImageSource;
use App\Enums\ProvisioningStatus;
use App\Enums\RunStatus;
use App\Enums\SiteStatus;
use App\Filament\Resources\Deployments\Pages\ViewDeployment;
use App\Filament\Resources\Sites\Pages\ViewSite;
use App\Filament\Resources\Sites\RelationManagers\DeploymentsRelationManager;
use App\Filament\Resources\Sites\Schemas\SiteForm;
use App\Jobs\DeploySiteJob;
use App\Livewire\DeploymentLog;
use App\Models\Deployment;
use App\Models\Registry;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\Redactor;
use Filament\Actions\Testing\TestAction;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fakes\FakeConnection;
use Tests\Fakes\FakeConnector;

const SHA = '3f9c2a1b7d4e5f60718293a4b5c6d7e8f9012345';

beforeEach(function () {
    $this->connection = new FakeConnection;
    $this->app->instance(ServerConnector::class, new FakeConnector($this->connection));

    $this->server = Server::factory()->create();
    $this->server->forceFill(['provisioning_status' => ProvisioningStatus::Ready, 'architecture' => 'x86_64'])->save();

    $this->registry = Registry::create([
        'name' => 'GHCR', 'host' => 'ghcr.io', 'username' => 'acme', 'password' => 'ghp_registry-token-123',
    ]);

    // git and docker on this machine.
    Process::fake(fn (PendingProcess $process) => match (true) {
        $process->command[0] === 'git' && $process->command[1] === 'clone' => Process::result("Cloning into 'build'...\n"),
        $process->command[0] === 'git' => Process::result(SHA."\n"),
        array_slice($process->command, 0, 3) === ['docker', 'buildx', 'build'] => Process::result(
            "#1 [internal] load build definition\n#12 pushing ghcr.io/acme/shop with token ghp_registry-token-123\n",
        ),
        array_slice($process->command, 0, 2) === ['docker', 'context'] => Process::result("unix:///var/run/docker.sock\n"),
        default => Process::result(),
    });

    Queue::fake();
});

function deployableSite(Server $server, array $overrides = []): Site
{
    return CreateSite::run([
        'server_id' => $server->id,
        'name' => 'Shop',
        'domain' => 'shop.example.com',
        'repository_url' => 'git@github.com:acme/shop.git',
        'image_repository' => 'ghcr.io/acme/shop',
        'registry_id' => Registry::sole()->id,
        ...$overrides,
    ]);
}

function deployedAt(Site $site, string $image): Deployment
{
    $site->forceFill(['current_image' => $image, 'status' => SiteStatus::Running])->save();

    return $site->deployments()->create([
        'trigger' => DeploymentTrigger::Manual, 'status' => RunStatus::Succeeded, 'image' => $image,
    ]);
}

/** @return array<string, string> step name => status */
function deploymentSteps(Deployment $deployment): array
{
    return $deployment->steps()->get()->mapWithKeys(fn ($step) => [$step->name => $step->status->value])->all();
}

function lastCompose(FakeConnection $connection): string
{
    return $connection->uploads['/opt/mini-forge/sites/shop-example-com/compose.yaml']['contents'];
}

it('builds, pushes and releases a commit', function () {
    $site = deployableSite($this->server);

    $deployment = StartDeployment::run($site);
    Queue::assertPushed(DeploySiteJob::class);
    DeploySite::run($deployment);

    $deployment->refresh();
    $image = 'ghcr.io/acme/shop:'.substr(SHA, 0, 12).'-'.$deployment->id;

    expect($deployment->status)->toBe(RunStatus::Succeeded)
        ->and($deployment->error)->toBeNull()
        ->and($deployment->commit_sha)->toBe(SHA)
        ->and($deployment->branch)->toBe('main')
        ->and($deployment->image)->toBe($image)
        ->and($deployment->previous_image)->toBeNull()
        ->and(deploymentSteps($deployment))->toBe([
            'Clone repository' => 'succeeded',
            'Build and push image' => 'succeeded',
            'Write configuration' => 'succeeded',
            'Pull image' => 'succeeded',
            'Run migrations' => 'succeeded',
            'Start new containers' => 'succeeded',
            'Check the site through Traefik' => 'succeeded',
            'Remove old images' => 'succeeded',
        ])
        ->and($site->refresh()->current_image)->toBe($image)
        ->and($site->status)->toBe(SiteStatus::Running)
        ->and(lastCompose($this->connection))->toContain('image: "'.$image.'"')
        // Heals a .env that Docker turned into a directory.
        ->and($this->connection->ran('/if \[ -d "\$path" \] && \[ ! -L "\$path" \]; then rm -rf/'))->toBeTrue();

    Process::assertRan(fn (PendingProcess $p) => $p->command === [
        'git', 'clone', '--depth', '1', '--single-branch', '--branch', 'main', '--',
        'git@github.com:acme/shop.git', storage_path('app/private/builds/'.$deployment->id),
    ] && $p->environment['GIT_TERMINAL_PROMPT'] === '0');

    Process::assertRan(function (PendingProcess $p) use ($image) {
        if (array_slice($p->command, 0, 3) !== ['docker', 'buildx', 'build']) {
            return false;
        }

        // Pushes with a throwaway Docker config, not ~/.docker.
        return in_array('linux/amd64', $p->command, true)
            && in_array($image, $p->command, true)
            && in_array('--push', $p->command, true)
            && in_array('PHP_VERSION=8.4', $p->command, true)
            && str_contains($p->environment['DOCKER_CONFIG'], 'storage/app/private/docker-');
    });

    // Order on the server: pull, migrate in a one-off container, release, check.
    $scripts = collect($this->connection->scripts);
    $index = fn (string $pattern) => $scripts->search(fn (string $s) => preg_match($pattern, $s) === 1);

    expect($index('/DOCKER_CONFIG="\$config" docker pull/'))->toBeInt()
        ->and($index('/run --rm -T --no-deps --label traefik.enable=false web php artisan migrate --force/'))->toBeGreaterThan($index('/docker pull/'))
        ->and($index('/up -d --force-recreate/'))->toBeGreaterThan($index('/artisan migrate/'))
        ->and($index('/curl -sk .*--resolve \'shop.example.com:443:127.0.0.1\' \'https:\/\/shop.example.com\/up\'/'))->toBeGreaterThan($index('/force-recreate/'))
        ->and($index('/docker image ls/'))->toBeGreaterThan($index('/curl -sk/'));
});

it('keeps registry tokens and secret environment values out of logs and commands', function () {
    $site = deployableSite($this->server);
    $dbPassword = $site->environmentVariables()->where('key', 'DB_PASSWORD')->value('value');
    $this->connection->on('/artisan migrate/', "Connecting with password {$dbPassword}\nINFO  Nothing to migrate.");

    $deployment = StartDeployment::run($site);
    DeploySite::run($deployment);

    $logs = $deployment->steps()->pluck('output')->implode("\n");

    expect($logs)->toContain('pushing ghcr.io/acme/shop with token '.Redactor::MASK)
        ->and($logs)->toContain('Connecting with password '.Redactor::MASK)
        ->and($logs)->not->toContain('ghp_registry-token-123')
        ->and($logs)->not->toContain($dbPassword)
        ->and(implode("\n", $this->connection->scripts))->not->toContain('ghp_registry-token-123')
        ->and(collect($this->connection->uploads)->filter(fn ($u) => str_contains($u['contents'], base64_encode('acme:ghp_registry-token-123'))))
        ->toHaveCount(1)
        ->and(glob(storage_path('app/private/docker-*')))->toBe([])
        ->and(is_dir(storage_path('app/private/builds/'.$deployment->id)))->toBeFalse();
});

it('switches back to the previous image when the new release fails its check', function () {
    $site = deployableSite($this->server);
    deployedAt($site, 'ghcr.io/acme/shop:old');
    $this->connection->fail('/curl -sk/', 'Expected HTTP 2xx through Traefik, got 502.');

    $deployment = StartDeployment::run($site);
    DeploySite::run($deployment);
    $deployment->refresh();

    expect($deployment->status)->toBe(RunStatus::Failed)
        ->and($deployment->error)->toContain('Check the site through Traefik failed')
        ->and($deployment->error)->toContain('got 502')
        ->and($deployment->previous_image)->toBe('ghcr.io/acme/shop:old')
        ->and(deploymentSteps($deployment))->toMatchArray([
            'Start new containers' => 'succeeded',
            'Check the site through Traefik' => 'failed',
            'Remove old images' => 'skipped',
            'Switch back to the previous image' => 'succeeded',
        ])
        ->and($site->refresh()->current_image)->toBe('ghcr.io/acme/shop:old')
        ->and($site->status)->toBe(SiteStatus::Running)
        ->and(lastCompose($this->connection))->toContain('image: "ghcr.io/acme/shop:old"')
        ->and(collect($this->connection->scripts)->filter(fn ($s) => str_contains($s, '--force-recreate')))->toHaveCount(2);
});

it('leaves the running release alone when migrations fail', function () {
    $site = deployableSite($this->server);
    deployedAt($site, 'ghcr.io/acme/shop:old');
    $this->connection->fail('/artisan migrate/', 'SQLSTATE[42P07]: Duplicate table');

    $deployment = StartDeployment::run($site);
    DeploySite::run($deployment);
    $deployment->refresh();

    expect($deployment->status)->toBe(RunStatus::Failed)
        ->and($deployment->error)->toContain('Run migrations failed')
        ->and(deploymentSteps($deployment))->toMatchArray([
            'Run migrations' => 'failed',
            'Start new containers' => 'skipped',
            'Check the site through Traefik' => 'skipped',
            'Switch back to the previous image' => 'succeeded',
        ])
        ->and($this->connection->ran('/--force-recreate/'))->toBeFalse()
        ->and(lastCompose($this->connection))->toContain('image: "ghcr.io/acme/shop:old"')
        ->and($site->refresh()->status)->toBe(SiteStatus::Running)
        ->and($site->current_image)->toBe('ghcr.io/acme/shop:old');
});

it('marks a failed first release as failed with nothing to switch back to', function () {
    $site = deployableSite($this->server);
    $this->connection->fail('/docker inspect -f/', 'The web container is unhealthy.');

    $deployment = StartDeployment::run($site);
    DeploySite::run($deployment);

    expect($deployment->refresh()->status)->toBe(RunStatus::Failed)
        ->and(deploymentSteps($deployment))->not->toHaveKey('Switch back to the previous image')
        ->and($site->refresh()->status)->toBe(SiteStatus::Failed)
        ->and($site->current_image)->toBeNull();
});

it('stops before touching the server when the build fails', function () {
    $site = deployableSite($this->server);
    deployedAt($site, 'ghcr.io/acme/shop:old');
    Process::fake(fn (PendingProcess $p) => $p->command[0] === 'git'
        ? Process::result(errorOutput: "fatal: Remote branch main not found in upstream origin\n", exitCode: 128)
        : Process::result());

    $deployment = StartDeployment::run($site);
    DeploySite::run($deployment);
    $deployment->refresh();

    expect($deployment->status)->toBe(RunStatus::Failed)
        ->and($deployment->error)->toContain('Remote branch main not found')
        ->and(deploymentSteps($deployment))->toMatchArray([
            'Clone repository' => 'failed',
            'Build and push image' => 'skipped',
            'Write configuration' => 'skipped',
        ])
        ->and($this->connection->scripts)->toBe([])
        ->and($site->refresh()->status)->toBe(SiteStatus::Running);
});

it('deploys a prebuilt image by tag without building', function () {
    $site = deployableSite($this->server, ['image_source' => ImageSource::Registry]);

    expect(fn () => StartDeployment::run($site))->toThrow(RuntimeException::class, 'Give the image tag')
        ->and(fn () => StartDeployment::run($site, tag: 'v1; rm -rf /'))->toThrow(RuntimeException::class, 'Give the image tag');

    $deployment = StartDeployment::run($site, tag: 'v1.4.0');
    DeploySite::run($deployment);

    expect($deployment->refresh()->status)->toBe(RunStatus::Succeeded)
        ->and($deployment->image)->toBe('ghcr.io/acme/shop:v1.4.0')
        ->and($deployment->commit_sha)->toBeNull()
        ->and(deploymentSteps($deployment))->toMatchArray(['Clone repository' => 'skipped', 'Build and push image' => 'skipped', 'Run migrations' => 'succeeded'])
        ->and($site->refresh()->current_image)->toBe('ghcr.io/acme/shop:v1.4.0');

    Process::assertNothingRan();
});

it('redeploys the running image without building or migrating', function () {
    $site = deployableSite($this->server);
    deployedAt($site, 'ghcr.io/acme/shop:abc-1');

    $deployment = StartDeployment::run($site, DeploymentTrigger::Redeploy);
    DeploySite::run($deployment);

    expect($deployment->refresh()->status)->toBe(RunStatus::Succeeded)
        ->and($deployment->image)->toBe('ghcr.io/acme/shop:abc-1')
        ->and(deploymentSteps($deployment))->toMatchArray([
            'Build and push image' => 'skipped', 'Run migrations' => 'skipped', 'Start new containers' => 'succeeded',
        ])
        ->and($this->connection->ran('/artisan migrate/'))->toBeFalse();

    Process::assertNothingRan();
});

it('rolls back to an earlier successful deployment', function () {
    $site = deployableSite($this->server);
    $first = deployedAt($site, 'ghcr.io/acme/shop:one');
    $failed = $site->deployments()->create(['status' => RunStatus::Failed, 'image' => 'ghcr.io/acme/shop:broken']);
    $current = deployedAt($site, 'ghcr.io/acme/shop:two');

    expect(fn () => StartDeployment::run($site, DeploymentTrigger::Rollback, rollbackTo: $failed))->toThrow(RuntimeException::class, 'Only a successful')
        ->and(fn () => StartDeployment::run($site, DeploymentTrigger::Rollback, rollbackTo: $current))->toThrow(RuntimeException::class, 'already running')
        ->and(fn () => StartDeployment::run($site, DeploymentTrigger::Rollback, rollbackTo: deployedAt(deployableSite($this->server, ['domain' => 'other.example.com']), 'ghcr.io/acme/other:1')))
        ->toThrow(RuntimeException::class, 'Only a successful');

    $deployment = StartDeployment::run($site, DeploymentTrigger::Rollback, rollbackTo: $first);
    DeploySite::run($deployment);

    expect($deployment->refresh()->status)->toBe(RunStatus::Succeeded)
        ->and($deployment->previous_image)->toBe('ghcr.io/acme/shop:two')
        ->and($site->refresh()->current_image)->toBe('ghcr.io/acme/shop:one')
        ->and($this->connection->ran('/artisan migrate/'))->toBeFalse()
        // Keeps the images worth rolling back to.
        ->and($this->connection->ran("/keep=' ghcr.io\/acme\/shop:one ghcr.io\/acme\/shop:two /"))->toBeTrue();
});

it('runs one deployment of a site at a time', function () {
    $site = deployableSite($this->server);
    $stale = StartDeployment::run($site);

    expect(fn () => StartDeployment::run($site))->toThrow(RuntimeException::class, 'already in progress');

    $stale->forceFill(['created_at' => now()->subSeconds(DeploySiteJob::TIMEOUT + 60)])->save();
    StartDeployment::run($site);

    expect($stale->refresh()->status)->toBe(RunStatus::Failed)
        ->and($stale->error)->toContain('Interrupted')
        ->and($stale->steps()->where('status', RunStatus::Pending)->count())->toBe(0);
});

it('refuses to deploy before the server is ready or with a registry that does not host the image', function () {
    $site = deployableSite($this->server, ['image_repository' => 'docker.io/acme/shop']);

    expect(fn () => StartDeployment::run($site))->toThrow(RuntimeException::class, 'does not host docker.io/acme/shop');

    $this->server->forceFill(['provisioning_status' => ProvisioningStatus::Pending])->save();

    expect(fn () => StartDeployment::run($site->refresh()))->toThrow(RuntimeException::class, 'Provision the server');
});

it('marks a deployment the worker never finished as failed', function () {
    $site = deployableSite($this->server);
    $deployment = StartDeployment::run($site);
    $site->markStatus(SiteStatus::Deploying);

    (new DeploySiteJob($deployment))->failed(new RuntimeException('Job timed out'));

    expect($deployment->refresh()->status)->toBe(RunStatus::Failed)
        ->and($deployment->error)->toBe('Interrupted: Job timed out')
        ->and($site->refresh()->status)->toBe(SiteStatus::Failed);
});

it('starts a deployment from the site page and shows its log', function () {
    $this->actingAs($user = User::factory()->create());
    $site = deployableSite($this->server);

    Livewire::test(ViewSite::class, ['record' => $site->getRouteKey()])
        ->callAction('deploy')
        ->assertRedirect();

    $deployment = Deployment::sole();
    expect($deployment->user_id)->toBe($user->id);
    Queue::assertPushed(DeploySiteJob::class, fn (DeploySiteJob $job) => $job->deployment->is($deployment));

    DeploySite::run($deployment);

    Livewire::test(ViewDeployment::class, ['record' => $deployment->getRouteKey()])
        ->assertOk()
        ->assertSee("Deployment #{$deployment->id}");
    Livewire::test(DeploymentLog::class, ['deployment' => $deployment])
        ->assertSee('Build and push image')
        ->assertSee($deployment->refresh()->image)
        ->assertSee('#12 pushing ghcr.io/acme/shop with token [redacted]')
        ->assertDontSee('ghp_registry-token-123')
        ->assertDontSeeHtml('wire:poll');
});

it('asks for a tag when deploying a prebuilt image', function () {
    $this->actingAs(User::factory()->create());
    $site = deployableSite($this->server, ['image_source' => ImageSource::Registry]);

    Livewire::test(ViewSite::class, ['record' => $site->getRouteKey()])
        ->callAction('deploy', ['tag' => '../../etc'])
        ->assertHasActionErrors(['tag']);

    Livewire::test(ViewSite::class, ['record' => $site->getRouteKey()])
        ->callAction('deploy', ['tag' => 'v2.0.0'])
        ->assertRedirect();

    expect(Deployment::sole()->image)->toBe('ghcr.io/acme/shop:v2.0.0');
});

it('rolls back from the deployments tab', function () {
    $this->actingAs(User::factory()->create());
    $site = deployableSite($this->server);
    $first = deployedAt($site, 'ghcr.io/acme/shop:one');
    $current = deployedAt($site, 'ghcr.io/acme/shop:two');

    Livewire::test(DeploymentsRelationManager::class, ['ownerRecord' => $site, 'pageClass' => ViewSite::class])
        ->assertActionHidden(TestAction::make('rollback')->table($current))
        ->callAction(TestAction::make('rollback')->table($first))
        ->assertRedirect();

    expect(Deployment::latest('id')->first())
        ->trigger->toBe(DeploymentTrigger::Rollback)
        ->image->toBe('ghcr.io/acme/shop:one');
});

it('builds with the Dockerfile the site names, and fails clearly if it is missing', function () {
    $site = deployableSite($this->server, ['dockerfile_path' => 'docker/Production.Dockerfile']);

    Process::fake(function (PendingProcess $process) {
        if ($process->command[1] === 'clone') {
            $directory = end($process->command);
            mkdir($directory.'/docker', 0777, true);
            touch($directory.'/docker/Production.Dockerfile');
        }

        return $process->command[0] === 'git' && $process->command[1] !== 'clone' ? Process::result(SHA."\n") : Process::result();
    });

    $deployment = StartDeployment::run($site);
    DeploySite::run($deployment);

    expect($deployment->refresh()->status)->toBe(RunStatus::Succeeded);
    Process::assertRan(fn (PendingProcess $p) => in_array('docker/Production.Dockerfile', $p->command, true));

    Process::fake(fn (PendingProcess $p) => $p->command[0] === 'git' && $p->command[1] !== 'clone' ? Process::result(SHA."\n") : Process::result());
    $deployment = StartDeployment::run($site);
    DeploySite::run($deployment);

    expect($deployment->refresh()->status)->toBe(RunStatus::Failed)
        ->and($deployment->error)->toContain('The repository has no docker/Production.Dockerfile');
});

it('rejects a Dockerfile path outside the repository', function (string $path) {
    expect(preg_match(SiteForm::DOCKERFILE_PATTERN, $path))->toBe(0);
})->with(['/etc/passwd', '../Dockerfile', 'a/../../b', '--file=x', '.env', 'a b']);
