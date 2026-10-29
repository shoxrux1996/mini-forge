<?php

declare(strict_types=1);

use App\Infrastructure\Docker\ComposeGenerator;
use App\Models\Server;

it('renders a locked-down infrastructure stack', function () {
    $yaml = app(ComposeGenerator::class)->infrastructure(Server::factory()->make(['acme_email' => 'ops@example.com']));

    expect($yaml)
        ->toContain('--providers.docker.exposedbydefault=false')
        ->toContain('--providers.docker.endpoint=tcp://socket-proxy:2375')
        ->toContain('--api.dashboard=false')
        ->toContain('"--certificatesresolvers.letsencrypt.acme.email=ops@example.com"')
        ->toContain('/var/run/docker.sock:/var/run/docker.sock:ro')
        ->toContain('POST: "0"')
        ->toContain('internal: true')
        ->toContain('name: "mini-forge"')
        ->not->toContain('--api.insecure')
        ->not->toContain('8080:8080')
        ->not->toContain('5432:')
        ->not->toContain('6379:')
        ->toContain('--aclfile')
        ->toContain('name: "mini-forge-data"');
});

it('refuses to render without a valid email', function (?string $email) {
    app(ComposeGenerator::class)->infrastructure(Server::factory()->make(['acme_email' => $email]));
})->with([null, 'not-an-email', "ops@example.com\n  - --api.insecure=true"])->throws(InvalidArgumentException::class);
