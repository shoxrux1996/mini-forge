<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;
use phpseclib4\Crypt\EC;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    private static ?string $privateKey = null;

    public function definition(): array
    {
        // Generating keys is slow-ish, so tests share one.
        self::$privateKey ??= EC::createKey('Ed25519')->toString('OpenSSH');

        return [
            'name' => fake()->unique()->domainWord(),
            'host' => fake()->unique()->ipv4(),
            'ssh_port' => 22,
            'ssh_user' => 'root',
            'ssh_private_key' => self::$privateKey,
        ];
    }

    public function reachable(): static
    {
        return $this->state(['last_connected_at' => now()]);
    }
}
