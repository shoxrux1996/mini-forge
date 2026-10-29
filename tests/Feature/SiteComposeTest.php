<?php

declare(strict_types=1);

use App\Actions\Sites\CreateSite;
use App\Enums\ServiceRole;
use App\Infrastructure\Docker\ComposeGenerator;
use App\Infrastructure\Docker\ComposeManager;
use App\Models\Server;

function makeSite(array $overrides = [])
{
    return CreateSite::run([
        'server_id' => Server::factory()->create()->id,
        'name' => 'Shop',
        'domain' => 'shop.example.com',
        'repository_url' => 'git@github.com:acme/shop.git',
        'image_repository' => 'ghcr.io/acme/shop',
        ...$overrides,
    ]);
}

it('routes the web service through Traefik with a health check', function () {
    $site = makeSite(['health_check_path' => '/health']);
    $site->forceFill(['current_image' => 'ghcr.io/acme/shop:abc123'])->save();

    $yaml = app(ComposeGenerator::class)->site($site);

    expect($yaml)
        ->toContain('name: "shop-example-com"')
        ->toContain('image: "ghcr.io/acme/shop:abc123"')
        ->toContain('traefik.enable: "true"')
        ->toContain('traefik.http.routers.shop-example-com.rule: "Host(`shop.example.com`)"')
        ->toContain('traefik.http.services.shop-example-com.loadbalancer.server.port: "8080"')
        ->toContain('"http://127.0.0.1:8080/health"')
        ->toContain('php artisan queue:work')
        ->toContain('php artisan schedule:work')
        ->toContain('name: "mini-forge-data"');
});

it('mounts only storage and the .env file, never source code', function () {
    $yaml = app(ComposeGenerator::class)->site(makeSite());

    preg_match_all('/^\s+- (\S+:\/app\S*)$/m', $yaml, $mounts);

    expect($mounts[1])->toBe(['storage:/app/storage', './.env:/app/.env:ro'])
        ->and($yaml)->toContain('image: "ghcr.io/acme/shop:not-deployed"');
});

it('leaves out disabled services', function () {
    $site = makeSite(['queue_enabled' => false, 'scheduler_enabled' => false]);

    $yaml = app(ComposeGenerator::class)->site($site);

    expect($yaml)->toContain('  web:')
        ->not->toContain('  worker:')
        ->not->toContain('  scheduler:')
        ->and($site->serviceEnabled(ServiceRole::Worker))->toBeFalse();
});

it('reads compose ps output in both formats', function () {
    $line = '{"Name":"shop-web-1","Service":"web","State":"running","Health":"healthy","Status":"Up 2 minutes (healthy)","Image":"ghcr.io/acme/shop:abc"}';

    expect(ComposeManager::parseContainers($line."\n".$line))->toHaveCount(2)
        ->and(ComposeManager::parseContainers('['.$line.']')[0])->toMatchArray(['service' => 'web', 'health' => 'healthy'])
        ->and(ComposeManager::parseContainers(''))->toBe([])
        ->and(ComposeManager::parseContainers('not json'))->toBe([]);
});
