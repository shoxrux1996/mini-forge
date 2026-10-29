<?php

declare(strict_types=1);

use App\Enums\ServiceRole;
use App\Models\Deployment;
use App\Models\Site;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('derives a stable slug from the domain', function () {
    $site = Site::factory()->create(['domain' => 'api.example.com']);

    expect($site->slug)->toBe('api-example-com')
        ->and($site->remotePath())->toBe('/opt/mini-forge/sites/api-example-com');

    $site->update(['domain' => 'v2.example.com']);

    expect($site->refresh()->slug)->toBe('api-example-com');
});

it('keeps sites on one server isolated by slug', function () {
    $site = Site::factory()->create(['domain' => 'shop.example.com']);

    expect(fn () => Site::factory()->for($site->server)->create([
        'domain' => 'other.example.com',
        'slug' => 'shop-example-com',
    ]))->toThrow(QueryException::class);
});

it('encrypts every environment value at rest', function () {
    $site = Site::factory()->create();
    $site->environmentVariables()->create(['key' => 'APP_NAME', 'value' => 'Shop']);
    $site->environmentVariables()->create(['key' => 'DB_PASSWORD', 'value' => 's3cret', 'is_sensitive' => true]);

    $raw = DB::table('site_environment_variables')->pluck('value');

    expect($raw)->each->not->toContain('Shop')
        ->and($raw)->each->not->toContain('s3cret')
        ->and($site->environmentVariables()->where('key', 'DB_PASSWORD')->value('value'))->toBe('s3cret');
});

it('allows one service per role per site', function () {
    $site = Site::factory()->create();
    $site->services()->create(['role' => ServiceRole::Worker]);

    expect(fn () => $site->services()->create(['role' => ServiceRole::Worker]))
        ->toThrow(QueryException::class);
});

it('computes deployment duration', function () {
    $deployment = Site::factory()->create()->deployments()->create([
        'started_at' => now()->subSeconds(95),
        'finished_at' => now(),
    ]);

    expect($deployment->durationInSeconds())->toBe(95)
        ->and((new Deployment)->durationInSeconds())->toBeNull();
});
