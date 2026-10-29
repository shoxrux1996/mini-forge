<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->domainWord();

        return [
            'server_id' => Server::factory(),
            'name' => $name,
            'domain' => $name.'.example.com',
            'repository_url' => "git@github.com:acme/{$name}.git",
            'image_repository' => "ghcr.io/acme/{$name}",
        ];
    }
}
