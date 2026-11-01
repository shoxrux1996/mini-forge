<?php

declare(strict_types=1);

namespace App\Infrastructure\Docker;

use App\Contracts\ServerConnection;
use App\Data\CommandResult;
use App\Enums\ArtisanCommand;
use App\Enums\ServiceRole;
use App\Infrastructure\SSH\Shell;
use App\Models\Registry;
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

    /** Logs shown at once; lines can be long, and they pass through the browser. */
    private const int LOG_BYTES = 262_144;

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

        // Older Docker setups create a missing bind-mount source as an empty
        // directory; neither file is ever legitimately a directory.
        $this->run(
            'for file in .env compose.yaml; do path='.Shell::quote($directory).'/$file; '
            .'if [ -d "$path" ] && [ ! -L "$path" ]; then rm -rf -- "$path"; echo "Replaced the directory $file with a file."; fi; done',
            'Checking the site files',
        );

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
    public function recreate(): string
    {
        $output = $this->compose('up -d --force-recreate --remove-orphans', 'Recreating containers', 600)->combinedOutput();

        return trim($output."\n".$this->waitUntilHealthy());
    }

    /**
     * Pulls the image with a throwaway Docker config holding only this
     * registry's login; the server's own Docker config is left alone.
     */
    public function pull(string $image, ?Registry $registry): string
    {
        $pull = 'docker pull '.Shell::quote($this->imageReference($image));

        if ($registry === null) {
            return $this->run($pull, 'Pulling the image', 900)->combinedOutput();
        }

        $path = $this->site->remotePath().'/.mini-forge-'.bin2hex(random_bytes(6));
        $this->connection->upload($path, $registry->dockerConfig(), 0600);
        $file = Shell::quote($path);

        $script = <<<BASH
            config=\$(mktemp -d)
            trap 'rm -rf "\$config" {$file}' EXIT
            install -m 0600 {$file} "\$config/config.json"
            DOCKER_CONFIG="\$config" {$pull}
            BASH;

        return $this->run($script, 'Pulling the image', 900)->combinedOutput();
    }

    /**
     * Runs migrations in a one-off container of the new image before it
     * receives traffic. Traefik is told to ignore that container.
     */
    public function migrate(): string
    {
        return $this->compose(
            'run --rm -T --no-deps --label traefik.enable=false web php artisan migrate --force --no-interaction',
            'Running migrations',
            900,
        )->combinedOutput();
    }

    /**
     * Requests the health check path through Traefik on the server itself,
     * so it checks routing as well as the app. Certificates may still be
     * pending, so TLS is not verified here.
     */
    public function verifyRoute(int $seconds = 60): string
    {
        $domain = $this->site->domain;
        $url = Shell::quote('https://'.$domain.$this->site->health_check_path);
        $resolve = Shell::quote($domain.':443:127.0.0.1');

        $script = <<<BASH
            code=000
            for _ in \$(seq 1 {$this->attempts($seconds)}); do
              code=\$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 --resolve {$resolve} {$url} || true)
              case "\$code" in 2??) echo "Traefik answered HTTP \$code for {$this->site->health_check_path}."; exit 0 ;; esac
              sleep 2
            done
            echo "Expected HTTP 2xx through Traefik, got \$code." >&2
            exit 1
            BASH;

        return $this->run($script, 'Checking the site through Traefik', $seconds + 30)->combinedOutput();
    }

    /**
     * Removes this site's images on the server except those in $keep, so
     * old releases do not fill the disk. Rollback pulls them again.
     *
     * @param  list<string>  $keep
     */
    public function removeImagesExcept(array $keep): string
    {
        $repository = Shell::quote($this->imageReference($this->site->image_repository));
        $kept = Shell::quote(' '.implode(' ', array_map($this->imageReference(...), $keep)).' ');

        $script = <<<BASH
            keep={$kept}
            removed=0
            for image in \$(docker image ls --format '{{.Repository}}:{{.Tag}}' {$repository}); do
              case "\$keep" in *" \$image "*) continue ;; esac
              if docker image rm "\$image" >/dev/null 2>&1; then echo "Removed \$image"; removed=\$((removed + 1)); fi
            done
            echo "\$removed old image(s) removed."
            BASH;

        return $this->run($script, 'Removing old images', 300)->combinedOutput();
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

    /** Runs an allowlisted command in the web container; the caller reads the result. */
    public function artisan(ArtisanCommand $command): CommandResult
    {
        return $this->connection->run(
            $this->composeCommand('exec -T web '.Shell::command('php', 'artisan', ...$command->arguments())),
            600,
        );
    }

    /** The last lines of a service's logs, at most LOG_BYTES of them. */
    public function logs(ServiceRole $service, int $lines): string
    {
        $command = $this->composeCommand('logs --no-color --no-log-prefix --timestamps --tail '.max(1, $lines).' '.$service->value);
        $output = $this->run($command.' 2>&1 | tail -c '.self::LOG_BYTES, 'Reading logs', 60)->output;

        return (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $output);
    }

    /**
     * What Docker reports about a service's container. Only the fields below
     * leave the server: `Config.Env` and the like never do.
     *
     * @return array<string, string>
     */
    public function inspect(ServiceRole $service): array
    {
        $ids = $this->composeCommand('ps --all --quiet '.$service->value);

        $output = $this->run(
            "ids=\$({$ids})\n".'if [ -z "$ids" ]; then echo "No container for this service." >&2; exit 1; fi'."\n".'docker inspect $ids',
            'Inspecting the container',
        )->output;

        return self::parseInspection($output);
    }

    /**
     * @return array<string, string>
     */
    public static function parseInspection(string $output): array
    {
        $container = json_decode($output, true)[0] ?? null;

        if (! is_array($container)) {
            throw new RuntimeException('Docker returned no container details.');
        }

        $health = data_get($container, 'State.Health.Log');
        $lastCheck = is_array($health) && $health !== [] ? end($health) : null;
        $memory = (int) data_get($container, 'HostConfig.Memory', 0);

        return array_filter([
            'Name' => ltrim((string) data_get($container, 'Name'), '/'),
            'Image' => (string) data_get($container, 'Config.Image'),
            'State' => (string) data_get($container, 'State.Status'),
            'Health' => (string) data_get($container, 'State.Health.Status'),
            'Started' => (string) data_get($container, 'State.StartedAt'),
            'Exit code' => (string) data_get($container, 'State.ExitCode'),
            'Out of memory' => data_get($container, 'State.OOMKilled') ? 'Yes, killed' : 'No',
            'Restarts' => (string) data_get($container, 'RestartCount'),
            'Memory limit' => $memory > 0 ? intdiv($memory, 1048576).' MB' : 'None',
            'Command' => implode(' ', array_map(strval(...), (array) data_get($container, 'Config.Cmd', []))),
            'Networks' => implode(', ', array_keys((array) data_get($container, 'NetworkSettings.Networks', []))),
            'Mounts' => implode(', ', array_map(
                fn (array $mount) => ($mount['Destination'] ?? '').(($mount['RW'] ?? true) ? '' : ' (read-only)'),
                array_filter((array) data_get($container, 'Mounts', []), is_array(...)),
            )),
            'Last health check' => is_array($lastCheck) ? trim((string) ($lastCheck['Output'] ?? '')) : '',
        ], fn (string $value) => $value !== '');
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

    private function waitUntilHealthy(int $seconds = 120): string
    {
        $container = Shell::quote($this->site->webContainer());

        $script = <<<BASH
            status=missing
            for _ in \$(seq 1 {$this->attempts($seconds)}); do
              status=\$(docker inspect -f '{{.State.Health.Status}}' {$container} 2>/dev/null || echo missing)
              if [ "\$status" = "healthy" ]; then echo "The web container is healthy."; exit 0; fi
              [ "\$status" = "unhealthy" ] && break
              sleep 2
            done
            echo "The web container is \$status." >&2
            docker logs --tail 40 {$container} >&2 || true
            exit 1
            BASH;

        return $this->run($script, 'Waiting for the health check', $seconds + 30)->output;
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

    private function imageReference(string $image): string
    {
        if (preg_match('~^[a-z0-9][a-z0-9._:/-]*(:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127})?$~', $image) !== 1) {
            throw new RuntimeException("Unexpected image reference [{$image}].");
        }

        return $image;
    }

    private function identifier(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,254}$/', $name) !== 1) {
            throw new RuntimeException("Unexpected identifier [{$name}].");
        }

        return $name;
    }

    private function redisQuote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
