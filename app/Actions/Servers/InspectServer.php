<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Contracts\ServerConnection;
use App\Exceptions\RemoteCommandFailed;
use App\Exceptions\ServerConnectionFailed;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class InspectServer
{
    use AsAction;

    private const string SCRIPT = <<<'BASH'
        . /etc/os-release
        echo "os_name=${NAME:-}"
        echo "os_version=${VERSION_ID:-}"
        echo "architecture=$(uname -m)"
        echo "cpu_cores=$(nproc)"
        echo "memory_mb=$(awk '/^MemTotal:/ {print int($2 / 1024)}' /proc/meminfo)"
        echo "disk_gb=$(df -BG --output=size / | tail -n 1 | tr -dc '0-9')"
        echo "uptime_seconds=$(cut -d. -f1 /proc/uptime)"
        echo "docker_version=$(docker version --format '{{.Server.Version}}' 2>/dev/null || true)"
        echo "compose_version=$(docker compose version --short 2>/dev/null || true)"
        BASH;

    /**
     * Reads OS, hardware and Docker facts and saves them with a timestamp.
     * Uses the given connection, or opens (and closes) its own.
     *
     * @throws ServerConnectionFailed
     * @throws RemoteCommandFailed
     */
    public function handle(Server $server, ?ServerConnection $connection = null): Server
    {
        $own = $connection === null;
        $connection ??= ConnectToServer::run($server);

        try {
            $output = $connection->run(self::SCRIPT, 30)->throw('Inspecting the server')->output;
        } finally {
            if ($own) {
                $connection->disconnect();
            }
        }

        $facts = self::parse($output);

        $server->forceFill([
            'os_name' => $facts['os_name'] ?? null,
            'os_version' => $facts['os_version'] ?? null,
            'architecture' => $facts['architecture'] ?? null,
            'cpu_cores' => self::integer($facts['cpu_cores'] ?? null),
            'memory_mb' => self::integer($facts['memory_mb'] ?? null),
            'disk_gb' => self::integer($facts['disk_gb'] ?? null),
            'uptime_seconds' => self::integer($facts['uptime_seconds'] ?? null),
            'docker_version' => $facts['docker_version'] ?? null,
            'compose_version' => $facts['compose_version'] ?? null,
            'inspected_at' => now(),
        ])->save();

        return $server;
    }

    /**
     * @return array<string, string>
     */
    public static function parse(string $output): array
    {
        $facts = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^([a-z_]+)=(.*)$/', trim($line), $match) === 1 && $match[2] !== '') {
                $facts[$match[1]] = mb_substr($match[2], 0, 255);
            }
        }

        return $facts;
    }

    private static function integer(?string $value): ?int
    {
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
