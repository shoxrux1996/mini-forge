<?php

declare(strict_types=1);

use App\Actions\Sites\ApplySiteConfiguration;
use App\Actions\Sites\CreateSite;
use App\Actions\Sites\RemoveSite;
use App\Actions\Sites\RestartSite;
use App\Actions\Sites\SiteOperation;
use App\Actions\Sites\StartSite;
use App\Actions\Sites\StopSite;
use App\Contracts\ServerConnector;
use App\Data\CommandResult;
use App\Enums\ProvisioningStatus;
use App\Enums\SiteStatus;
use App\Filament\Resources\Sites\Pages\CreateSite as CreateSitePage;
use App\Filament\Resources\Sites\Pages\ViewSite;
use App\Filament\Resources\Sites\RelationManagers\EnvironmentVariablesRelationManager;
use App\Livewire\SiteContainers;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Fakes\FakeConnection;
use Tests\Fakes\FakeConnector;

beforeEach(function () {
    $this->connection = new FakeConnection;
    $this->app->instance(ServerConnector::class, new FakeConnector($this->connection));
    $this->server = Server::factory()->create(['provisioning_status' => ProvisioningStatus::Ready]);
    $this->server->forceFill(['provisioning_status' => ProvisioningStatus::Ready])->save();
});

function createSite(Server $server, string $domain = 'shop.example.com', array $overrides = []): Site
{
    return CreateSite::run([
        'server_id' => $server->id,
        'name' => 'Shop',
        'domain' => $domain,
        'repository_url' => 'git@github.com:acme/shop.git',
        'image_repository' => 'ghcr.io/acme/shop',
        ...$overrides,
    ]);
}

function siteEnv(Site $site, string $key): ?string
{
    return $site->environmentVariables()->where('key', $key)->first()?->value;
}

it('creates a site with services and a production environment', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateSitePage::class)
        ->fillForm([
            'server_id' => $this->server->id,
            'name' => 'Shop',
            'domain' => 'shop.example.com',
            'repository_url' => 'git@github.com:acme/shop.git',
            'branch' => 'main',
            'image_repository' => 'ghcr.io/acme/shop',
            'queue_enabled' => true,
            'scheduler_enabled' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $site = Site::sole();

    expect($site->slug)->toBe('shop-example-com')
        ->and($site->status)->toBe(SiteStatus::Pending)
        ->and($site->services()->pluck('enabled', 'role')->map(fn ($e) => (bool) $e)->all())
        ->toEqualCanonicalizing(['web' => true, 'worker' => true, 'scheduler' => false])
        ->and(strlen(base64_decode(substr(siteEnv($site, 'APP_KEY'), 7))))->toBe(32)
        ->and(siteEnv($site, 'APP_URL'))->toBe('https://shop.example.com')
        ->and(siteEnv($site, 'DB_DATABASE'))->toBe('site_shop_example_com')
        ->and(siteEnv($site, 'REDIS_DB'))->toBe('1')
        ->and(siteEnv($site, 'REDIS_PREFIX'))->toBe('shop-example-com:')
        ->and(siteEnv($site, 'DB_PASSWORD'))->toHaveLength(40)
        ->and($site->environmentVariables()->where('is_sensitive', true)->pluck('key')->sort()->values()->all())
        ->toBe(['APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD']);
});

it('validates site settings', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateSitePage::class)
        ->fillForm([
            'server_id' => $this->server->id,
            'name' => 'Shop',
            'domain' => 'shop.example.com`) || Host(`evil.com',
            'repository_url' => 'file:///etc/passwd',
            'branch' => '--upload-pack=touch /tmp/x',
            'image_repository' => 'GHCR.io/Acme/Shop:latest',
            'health_check_path' => 'up; id',
        ])
        ->call('create')
        ->assertHasFormErrors(['domain', 'repository_url', 'branch', 'image_repository', 'health_check_path']);

    expect(Site::count())->toBe(0);
});

it('isolates sites on the same server', function () {
    $shop = createSite($this->server, 'shop.example.com');
    $api = createSite($this->server, 'api.example.com');

    expect($shop->slug)->not->toBe($api->slug)
        ->and($shop->remotePath())->not->toBe($api->remotePath())
        ->and($shop->databaseName())->not->toBe($api->databaseName())
        ->and($shop->redis_database)->not->toBe($api->redis_database)
        ->and($shop->redisUser())->not->toBe($api->redisUser())
        ->and(siteEnv($shop, 'DB_PASSWORD'))->not->toBe(siteEnv($api, 'DB_PASSWORD'))
        ->and(siteEnv($shop, 'APP_KEY'))->not->toBe(siteEnv($api, 'APP_KEY'));
});

it('reuses a Redis database only after its site is gone', function () {
    $first = createSite($this->server, 'one.example.com');
    $second = createSite($this->server, 'two.example.com');
    $first->delete();

    expect(createSite($this->server, 'three.example.com')->redis_database)->toBe($first->redis_database)
        ->and(createSite(Server::factory()->create(), 'other.example.com')->redis_database)->toBe(1)
        ->and($second->redis_database)->toBe(2);
});

it('applies configuration without passing secrets on the command line', function () {
    $site = createSite($this->server);
    $dbPassword = siteEnv($site, 'DB_PASSWORD');
    $redisPassword = siteEnv($site, 'REDIS_PASSWORD');

    ApplySiteConfiguration::run($site);

    $env = $this->connection->uploads['/opt/mini-forge/sites/shop-example-com/.env'];
    $compose = $this->connection->uploads['/opt/mini-forge/sites/shop-example-com/compose.yaml'];
    $secretFiles = collect($this->connection->uploads)->filter(fn ($u, $path) => str_contains($path, '/.mini-forge-'));

    expect($env['mode'])->toBe(0640)
        ->and($env['contents'])->toContain('DB_PASSWORD="'.$dbPassword.'"')
        ->and($compose['mode'])->toBe(0640)
        ->and($secretFiles)->toHaveCount(2)
        ->and($secretFiles->every(fn ($u) => $u['mode'] === 0600))->toBeTrue()
        ->and($secretFiles->pluck('contents')->implode("\n"))
        ->toContain("PASSWORD '{$dbPassword}'")
        ->toContain('">'.$redisPassword.'"')
        ->toContain('+select|1');

    $scripts = implode("\n", $this->connection->scripts);
    expect($scripts)->not->toContain($dbPassword)
        ->not->toContain($redisPassword)
        ->toContain('compose.yaml config --quiet')
        ->not->toContain(' up -d');

    expect($site->refresh()->status)->toBe(SiteStatus::Pending);
});

it('recreates containers when a deployed site is applied', function () {
    $site = createSite($this->server);
    $site->forceFill(['current_image' => 'ghcr.io/acme/shop:abc'])->save();

    ApplySiteConfiguration::run($site);

    // .env is a single-file mount, so containers must be recreated to see it.
    expect($this->connection->ran('/up -d --force-recreate --remove-orphans/'))->toBeTrue()
        ->and($this->connection->ran("/State.Health.Status}}' 'shop-example-com-web-1'/"))->toBeTrue()
        ->and($site->refresh()->status)->toBe(SiteStatus::Running);
});

it('marks the site failed when it does not become healthy', function () {
    $site = createSite($this->server);
    $site->forceFill(['current_image' => 'ghcr.io/acme/shop:abc'])->save();
    $this->connection->fail('/State.Health.Status/', 'The web container is unhealthy.');

    expect(fn () => StartSite::run($site))->toThrow(RuntimeException::class, 'unhealthy');
    expect($site->refresh()->status)->toBe(SiteStatus::Failed);
});

it('starts, stops and restarts a deployed site', function () {
    $site = createSite($this->server);

    expect(fn () => StartSite::run($site))->toThrow(RuntimeException::class, 'Deploy the site');

    $site->forceFill(['current_image' => 'ghcr.io/acme/shop:abc'])->save();

    expect(StartSite::run($site)->status)->toBe(SiteStatus::Running)
        ->and(StopSite::run($site)->status)->toBe(SiteStatus::Stopped)
        ->and($this->connection->ran('/compose.yaml stop$/'))->toBeTrue()
        ->and(RestartSite::run($site)->status)->toBe(SiteStatus::Running);
});

it('keeps a stopped site stopped when configuration is applied', function () {
    $site = createSite($this->server);
    $site->forceFill(['current_image' => 'ghcr.io/acme/shop:abc', 'status' => SiteStatus::Stopped])->save();

    ApplySiteConfiguration::run($site);

    expect($this->connection->ran('/up -d/'))->toBeFalse()
        ->and($site->refresh()->status)->toBe(SiteStatus::Stopped);
});

it('runs one operation per site at a time', function () {
    $site = createSite($this->server);
    $lock = Cache::lock(SiteOperation::lockName($site), 60);
    $lock->get();

    expect(fn () => ApplySiteConfiguration::run($site))->toThrow(RuntimeException::class, 'Another operation');
    expect($this->connection->scripts)->toBeEmpty();

    $lock->release();
});

it('needs a provisioned server', function () {
    $site = createSite(Server::factory()->create());

    expect(fn () => ApplySiteConfiguration::run($site))->toThrow(RuntimeException::class, 'Provision the server');
});

it('removes a site but keeps its database by default', function () {
    $site = createSite($this->server);

    RemoveSite::run($site, deleteData: false);

    $secrets = collect($this->connection->uploads)->pluck('contents')->implode("\n");
    expect(Site::count())->toBe(0)
        ->and($this->connection->ran('/down --remove-orphans;/'))->toBeTrue()
        ->and($this->connection->ran('/--volumes/'))->toBeFalse()
        ->and($secrets)->toContain("SELECT 1\nFLUSHDB\nACL DELUSER shop-example-com")
        ->and($secrets)->not->toContain('DROP DATABASE')
        ->and($this->connection->ran("/rm -rf -- '\\/opt\\/mini-forge\\/sites\\/shop-example-com'/"))->toBeTrue();
});

it('deletes the database and volume when asked', function () {
    $site = createSite($this->server);

    RemoveSite::run($site, deleteData: true);

    expect($this->connection->ran('/down --remove-orphans --volumes/'))->toBeTrue()
        ->and(collect($this->connection->uploads)->pluck('contents')->implode("\n"))
        ->toContain('DROP DATABASE IF EXISTS site_shop_example_com WITH (FORCE);');
});

it('asks for the domain before removing from the UI', function () {
    $this->actingAs(User::factory()->create());
    $site = createSite($this->server);

    Livewire::test(ViewSite::class, ['record' => $site->getRouteKey()])
        ->callAction('remove', ['confirmation' => 'wrong.example.com'])
        ->assertHasActionErrors(['confirmation']);

    expect(Site::count())->toBe(1);

    Livewire::test(ViewSite::class, ['record' => $site->getRouteKey()])
        ->callAction('remove', ['confirmation' => 'shop.example.com', 'delete_data' => false])
        ->assertHasNoActionErrors();

    expect(Site::count())->toBe(0);
});

it('edits environment variables and hides sensitive values', function () {
    $this->actingAs(User::factory()->create());
    $site = createSite($this->server);
    $password = siteEnv($site, 'DB_PASSWORD');

    $component = Livewire::test(EnvironmentVariablesRelationManager::class, ['ownerRecord' => $site, 'pageClass' => ViewSite::class]);

    $component->assertSee('DB_PASSWORD')
        ->assertDontSee($password)
        ->assertSee('site_shop_example_com');

    $component->callAction(TestAction::make('create')->table(), ['key' => 'MAIL_HOST', 'value' => 'smtp.example.com'])
        ->assertHasNoActionErrors();
    $fresh = fn () => Livewire::test(EnvironmentVariablesRelationManager::class, ['ownerRecord' => $site, 'pageClass' => ViewSite::class]);
    $fresh()->callAction(TestAction::make('create')->table(), ['key' => 'bad key', 'value' => 'x'])
        ->assertHasActionErrors(['key']);
    $fresh()->callAction(TestAction::make('create')->table(), ['key' => 'MAIL_HOST', 'value' => 'again'])
        ->assertHasActionErrors(['key']);

    expect(siteEnv($site, 'MAIL_HOST'))->toBe('smtp.example.com');
});

it('imports a pasted .env file', function () {
    $this->actingAs(User::factory()->create());
    $site = createSite($this->server);

    Livewire::test(EnvironmentVariablesRelationManager::class, ['ownerRecord' => $site, 'pageClass' => ViewSite::class])
        ->callAction(TestAction::make('import')->table(), ['contents' => "MAIL_HOST=smtp.example.com\nSTRIPE_SECRET=\"sk_test_123\"\nAPP_NAME='Shop Two'\n"]);

    $secret = $site->environmentVariables()->where('key', 'STRIPE_SECRET')->first();

    expect(siteEnv($site, 'MAIL_HOST'))->toBe('smtp.example.com')
        ->and($secret->value)->toBe('sk_test_123')
        ->and($secret->is_sensitive)->toBeTrue()
        ->and(siteEnv($site, 'APP_NAME'))->toBe('Shop Two');
});

it('keeps guests out of site pages', function () {
    $site = createSite($this->server);

    $this->get('/sites')->assertRedirect('/login');
    $this->get("/sites/{$site->id}")->assertRedirect('/login');
});

it('shows live containers on the site page', function () {
    $this->actingAs(User::factory()->create());
    $site = createSite($this->server);
    $this->connection->on('/ps --all --format json/', new CommandResult(0, '{"Name":"shop-example-com-web-1","Service":"web","State":"running","Health":"healthy","Status":"Up 5 minutes (healthy)","Image":"ghcr.io/acme/shop:abc"}'));

    Livewire::test(SiteContainers::class, ['site' => $site])
        ->call('load')
        ->assertSee('Up 5 minutes (healthy)')
        ->assertSee('Live from the server');
});
