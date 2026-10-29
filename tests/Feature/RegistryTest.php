<?php

declare(strict_types=1);

use App\Filament\Resources\Registries\Pages\ManageRegistries;
use App\Models\Registry;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('stores registry tokens encrypted and never shows them again', function () {
    Livewire::test(ManageRegistries::class)
        ->callAction('create', ['name' => 'GHCR', 'host' => 'ghcr.io', 'username' => 'acme', 'password' => 'ghp_secret-token'])
        ->assertHasNoActionErrors();

    $registry = Registry::sole();

    expect($registry->password)->toBe('ghp_secret-token')
        ->and(DB::table('registries')->value('password'))->not->toContain('ghp_secret-token')
        ->and($registry->toArray())->not->toHaveKey('password');

    Livewire::test(ManageRegistries::class)
        ->assertDontSee('ghp_secret-token')
        ->callAction(TestAction::make('edit')->table($registry), ['name' => 'GitHub', 'password' => ''])
        ->assertHasNoActionErrors();

    expect($registry->refresh())->name->toBe('GitHub')->password->toBe('ghp_secret-token');
});

it('validates the registry host', function () {
    Livewire::test(ManageRegistries::class)
        ->callAction('create', ['name' => 'Bad', 'host' => 'https://ghcr.io/x', 'username' => 'acme', 'password' => 'x'])
        ->assertHasActionErrors(['host']);
});

it('does not delete a registry that sites use', function () {
    $registry = Registry::create(['name' => 'GHCR', 'host' => 'ghcr.io', 'username' => 'acme', 'password' => 'token']);
    Site::factory()->for(Server::factory())->create(['registry_id' => $registry->id]);

    Livewire::test(ManageRegistries::class)->callAction(TestAction::make('delete')->table($registry));

    expect(Registry::count())->toBe(1);
});

it('knows which images it hosts', function (string $repository, string $host, bool $serves) {
    $registry = new Registry(['host' => $host]);

    expect($registry->serves($repository))->toBe($serves);
})->with([
    ['ghcr.io/acme/shop', 'ghcr.io', true],
    ['ghcr.io/acme/shop', 'docker.io', false],
    ['acme/shop', 'docker.io', true],
    ['docker.io/acme/shop', 'docker.io', true],
    ['localhost:5000/shop', 'localhost:5000', true],
    ['registry.example.com:5000/a/b', 'registry.example.com:5000', true],
]);

it('writes a Docker config with only its own login', function () {
    $config = json_decode((new Registry(['host' => 'docker.io', 'username' => 'acme', 'password' => 'pw']))->dockerConfig(), true);

    expect($config)->toBe(['auths' => ['https://index.docker.io/v1/' => ['auth' => base64_encode('acme:pw')]]]);
});
