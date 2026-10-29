<?php

declare(strict_types=1);

namespace App\Infrastructure\Docker;

use App\Contracts\ServerConnection;
use App\Data\CommandResult;
use App\Infrastructure\SSH\Shell;
use App\Models\Site;
use RuntimeException;

/**
 * Everything Mini Forge does to one site on its server: its directory,
 * database and Redis user, generated files, and its Compose project.
 */
final class ComposeManager
{
    /** Group of www-data in the Debian-based FrankenPHP images. */
    private const int WEB_GROUP = 33;

    public function __construct(
        private readonly ServerConnection $connection,
        private readonly Site $site,
    ) {}

    public function prepareDirectory(): void
    {
        $this->run('install -d -m 0750 '.Shell::quote($this->site->remotePath()), 'Creating the site directory');
    }

    /** Creates the site's Postgres role and database, or updates the password. */
    public function ensureDatabase(string $password): void
    {
        $name = $this->identifier($this->site->databaseName());
        $literal = "'".str_replace("'", "''", $password)."'";

        $sql = <<<SQL
            DO \$\$ BEGIN
              IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{$name}') THEN
                CREATE ROLE {$name} LOGIN;
              END IF;
            END \$\$;
            ALTER ROLE {$name} WITH LOGIN PASSWORD {$literal};
            SELECT 'CREATE DATABASE {$name} OWNER {$name}' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '{$name}') \\gexec
            REVOKE ALL ON DATABASE {$name} FROM PUBLIC;
            SQL;

        $this->withSecretFile($sql, 'docker exec -i mini-forge-postgres psql -q -v ON_ERROR_STOP=1 -U postgres', 'Creating the database');
    }

    /**
     * Creates or resets the site's Redis ACL user: any key, but only in its
     * own logical database, and no server-wide or cross-database commands.
     */
    public function ensureRedisUser(string $password): void
    {
        $user = $this->identifier($this->site->redisUser());
        $database = $this->site->redis_database;

        $commands = implode("\n", [
            "ACL SETUSER {$user} reset on ".$this->redisQuote('>'.$password)
                ." ~* &{$this->site->redisPrefix()}* +@all -@admin -flushall -swapdb -move -copy -migrate -function"
                ." -select +select|{$database}",
            'ACL SAVE',
        ])."\n";

        $this->withSecretFile($commands, $this->redisCli(), 'Creating the Redis user', redis: true);
    }

    public function writeFiles(string $env, string $compose): void
    {
        $directory = $this->site->remotePath();

        // Readable by the app user inside the container, nobody else.
        $this->connection->upload($directory.'/.env', $env, 0640);
        $this->run('chgrp '.self::WEB_GROUP.' '.Shell::quote($directory.'/.env'), 'Setting .env permissions');
        $this->connection->upload($directory.'/compose.yaml', $compose, 0640);

        $this->compose('config --quiet', 'Validating compose.yaml');
    }

    public function up(): void
    {
        $this->compose('up -d --remove-orphans', 'Starting containers', 600);
        $this->waitUntilHealthy();
    }

    /**
     * Recreates every container even when compose.yaml is unchanged: .env
     * is a single-file bind mount (replacing it does not reach running
     * containers) and Laravel caches config at start anyway.
     */
    public function recreate(): void
    {
        $this->compose('up -d --force-recreate --remove-orphans', 'Recreating containers', 600);
        $this->waitUntilHealthy();
    }

    public function stop(): void
    {
        $this->compose('stop', 'Stopping containers', 180);
    }

    public function restart(): void
    {
        $this->compose('restart', 'Restarting containers', 180);
        $this->waitUntilHealthy();
    }

    /**
     * @return list<array{name: string, service: string, state: string, health: string, status: string, image: string}>
     */
    public function containers(): array
    {
        // A site that was never applied has no directory yet: no containers.
        $output = $this->run(
            'if [ -f '.Shell::quote($this->site->remotePath().'/compose.yaml').' ]; then '
            .$this->composeCommand('ps --all --format json').'; fi',
            'Listing containers',
        )->output;

        return self::parseContainers($output);
    }

    /**
     * Stops and removes the site. Redis data (cache, queue, sessions) is
     * always cleared so the logical database can be reused; the Postgres
     * database and the storage volume are deleted only with $deleteData.
     */
    public function remove(bool $deleteData): void
    {
        $directory = $this->site->remotePath();
        $slug = $this->site->slug;

        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
            throw new RuntimeException("Refusing to remove an unexpected site path [{$slug}].");
        }

        $this->run(
            'if [ -f '.Shell::quote($directory.'/compose.yaml').' ]; then '
            .$this->composeCommand('down --remove-orphans'.($deleteData ? ' --volumes' : ''))
            .'; fi',
            'Removing containers',
            300,
        );

        $user = $this->identifier($this->site->redisUser());
        $this->withSecretFile(
            "SELECT {$this->site->redis_database}\nFLUSHDB\nACL DELUSER {$user}\nACL SAVE\n",
            $this->redisCli(),
            'Clearing Redis data',
            redis: true,
        );

        if ($deleteData) {
            $name = $this->identifier($this->site->databaseName());
            $this->withSecretFile(
                "DROP DATABASE IF EXISTS {$name} WITH (FORCE);\nDROP ROLE IF EXISTS {$name};\n",
                'docker exec -i mini-forge-postgres psql -q -v ON_ERROR_STOP=1 -U postgres',
                'Dropping the database',
            );
        }

        $this->run('rm -rf -- '.Shell::quote($directory), 'Removing the site directory');
    }

    /**
     * @return list<array{name: string, service: string, state: string, health: string, status: string, image: string}>
     */
    public static function parseContainers(string $output): array
    {
        $output = trim($output);

        if ($output === '') {
            return [];
        }

        // Compose prints one JSON object per line (older versions: one array).
        $rows = str_starts_with($output, '[')
            ? json_decode($output, true)
            : array_map(fn (string $line) => json_decode($line, true), preg_split('/\R/', $output) ?: []);

        return collect(is_array($rows) ? $rows : [])
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row) => [
                'name' => (string) ($row['Name'] ?? ''),
                'service' => (string) ($row['Service'] ?? ''),
                'state' => (string) ($row['State'] ?? ''),
                'health' => (string) ($row['Health'] ?? ''),
                'status' => (string) ($row['Status'] ?? ''),
                'image' => (string) ($row['Image'] ?? ''),
            ])
            ->values()
            ->all();
    }

    private function waitUntilHealthy(int $seconds = 120): void
    {
        $container = Shell::quote($this->site->webContainer());

        $script = <<<BASH
            status=missing
            for _ in \$(seq 1 {$this->attempts($seconds)}); do
              status=\$(docker inspect -f '{{.State.Health.Status}}' {$container} 2>/dev/null || echo missing)
              [ "\$status" = "healthy" ] && exit 0
              [ "\$status" = "unhealthy" ] && break
              sleep 2
            done
            echo "The web container is \$status." >&2
            docker logs --tail 40 {$container} >&2 || true
            exit 1
            BASH;

        $this->run($script, 'Waiting for the health check', $seconds + 30);
    }

    private function attempts(int $seconds): int
    {
        return max(1, intdiv($seconds, 2));
    }

    private function compose(string $arguments, string $what, int $timeout = 120): CommandResult
    {
        return $this->run($this->composeCommand($arguments), $what, $timeout);
    }

    private function composeCommand(string $arguments): string
    {
        // --env-file /dev/null: the site's .env is for Laravel, not for
        // Compose variable substitution.
        return 'cd '.Shell::quote($this->site->remotePath())
            .' && docker compose --env-file /dev/null -f compose.yaml '.$arguments;
    }

    private function redisCli(): string
    {
        return '. '.Shell::quote(config('mini-forge.remote_root').'/infrastructure/.env')
            .' && docker exec -i -e REDISCLI_AUTH="$REDIS_PASSWORD" mini-forge-redis redis-cli';
    }

    /**
     * Pipes $contents into $command through a temporary file uploaded over
     * SFTP, so secrets never appear in a process list.
     */
    private function withSecretFile(string $contents, string $command, string $what, bool $redis = false): void
    {
        $path = $this->site->remotePath().'/.mini-forge-'.bin2hex(random_bytes(6));
        $this->connection->upload($path, $contents, 0600);
        $file = Shell::quote($path);

        $result = $this->run(
            "trap 'rm -f {$file}' EXIT\n{$command} < {$file}",
            $what,
        );

        // redis-cli reports command errors on stdout and still exits 0.
        if ($redis && preg_match('/^(\(error\) )?(ERR|NOPERM|WRONGPASS)/m', $result->output) === 1) {
            throw new RuntimeException("{$what} failed: ".trim($result->output));
        }
    }

    private function run(string $script, string $what, int $timeout = 120): CommandResult
    {
        return $this->connection->run($script, $timeout)->throw($what);
    }

    private function identifier(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,62}$/', $name) !== 1) {
            throw new RuntimeException("Unexpected identifier [{$name}].");
        }

        return $name;
    }

    private function redisQuote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
