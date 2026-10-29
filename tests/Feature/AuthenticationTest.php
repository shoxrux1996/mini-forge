<?php

declare(strict_types=1);

use App\Filament\Widgets\StatsOverview;
use App\Models\Server;
use App\Models\User;
use Livewire\Livewire;

it('sends guests to the login page', function (string $path) {
    $this->get($path)->assertRedirect('/login');
})->with(['/', '/servers', '/servers/create']);

it('blocks guests from server pages even with a valid id', function () {
    $server = Server::factory()->create();

    $this->get("/servers/{$server->id}")->assertRedirect('/login');
});

it('shows the dashboard after login', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('Mini Forge');

    Livewire::test(StatsOverview::class)
        ->assertSee('Servers')
        ->assertSee('Failed deployments');
});

it('has no public registration page', function () {
    $this->get('/register')->assertNotFound();
});
