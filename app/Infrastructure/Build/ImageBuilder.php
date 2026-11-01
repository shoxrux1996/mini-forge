<?php

declare(strict_types=1);

namespace App\Infrastructure\Build;

use App\Models\Registry;
use App\Models\Site;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Clones a site's repository and builds its image on this machine with
 * docker buildx, then pushes it to the registry. Every command is an
 * argument list, never a shell string.
 */
final class ImageBuilder
{
    public const int CLONE_TIMEOUT = 600;

    public const int BUILD_TIMEOUT = 2700;

    /** Files of the default image, copied into .mini-forge/ when the repository has none. */
    private const array TEMPLATE_FILES = [
        'Dockerfile' => 'Dockerfile',
        'Caddyfile' => 'Caddyfile',
        'php.ini' => 'php.ini',
        'entrypoint.sh' => 'entrypoint.sh',
        'dockerignore' => 'Dockerfile.dockerignore',
    ];

    /**
     * Shallow-clones $branch into $directory and returns the commit SHA.
     *
     * @param  Closure(string): void  $output
     */
    public function clone(Site $site, string $branch, string $directory, Closure $output): string
    {
        File::ensureDirectoryExists(dirname($directory), 0700);
        File::deleteDirectory($directory);

        $this->run(
            ['git', 'clone', '--depth', '1', '--single-branch', '--branch', $branch, '--', $site->repository_url, $directory],
            'Cloning the repository',
            self::CLONE_TIMEOUT,
            $output,
            env: [
                // Fail instead of waiting for a password or a host-key prompt.
                'GIT_TERMINAL_PROMPT' => '0',
                'GIT_SSH_COMMAND' => 'ssh -o BatchMode=yes',
            ],
        );

        $sha = trim($this->run(['git', '-C', $directory, 'rev-parse', 'HEAD'], 'Reading the commit', 30)->output());

        if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new RuntimeException("Unexpected commit id [{$sha}].");
        }

        $output("Checked out {$branch} at {$sha}\n");

        return $sha;
    }

    /**
     * Uses the site's Dockerfile setting, then the repository's own
     * .mini-forge/Dockerfile, and otherwise copies in the default Laravel image.
     */
    public function prepareContext(Site $site, string $directory): string
    {
        if ($site->dockerfile_path !== null) {
            if (! is_file($directory.'/'.$site->dockerfile_path)) {
                throw new RuntimeException("The repository has no {$site->dockerfile_path}.");
            }

            return "Using the repository's {$site->dockerfile_path}.\n";
        }

        $target = $directory.'/.mini-forge';

        if (is_file($target.'/Dockerfile')) {
            return "Using the repository's .mini-forge/Dockerfile.\n";
        }

        File::ensureDirectoryExists($target);

        foreach (self::TEMPLATE_FILES as $source => $name) {
            File::copy(resource_path('templates/laravel/docker/'.$source), $target.'/'.$name);
        }

        return "Using Mini Forge's default Laravel image.\n";
    }

    /**
     * @param  Closure(string): void  $output
     */
    public function buildAndPush(Site $site, string $directory, string $image, string $platform, Closure $output): void
    {
        $config = $site->registry !== null ? $this->temporaryDockerConfig($site->registry) : null;

        try {
            $this->run(
                [
                    'docker', 'buildx', 'build',
                    '--platform', $platform,
                    '--file', $site->dockerfile_path ?? '.mini-forge/Dockerfile',
                    '--build-arg', 'PHP_VERSION='.$site->php_version->value,
                    '--tag', $image,
                    // One plain manifest; some registries reject attestation indexes.
                    '--provenance=false',
                    '--progress', 'plain',
                    '--push',
                    '.',
                ],
                'Building the image',
                self::BUILD_TIMEOUT,
                $output,
                path: $directory,
                env: $config === null ? [] : ['DOCKER_CONFIG' => $config, 'DOCKER_HOST' => $this->dockerHost()],
            );
        } finally {
            if ($config !== null) {
                File::deleteDirectory($config);
            }
        }

        // The image now lives in the registry; build cache stays for next time.
        Process::timeout(60)->run(['docker', 'image', 'rm', $image]);
    }

    /** buildx platform for a server's `uname -m`. */
    public static function platform(?string $architecture): string
    {
        return match ($architecture) {
            'x86_64', 'amd64' => 'linux/amd64',
            'aarch64', 'arm64' => 'linux/arm64',
            default => throw new RuntimeException('Unknown server architecture. Inspect the server first.'),
        };
    }

    /**
     * A private Docker config with only this registry's login, so your own
     * ~/.docker/config.json and its credential store are never touched.
     */
    private function temporaryDockerConfig(Registry $registry): string
    {
        $directory = storage_path('app/private/docker-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory, 0700);

        $config = json_decode($registry->dockerConfig(), true, flags: JSON_THROW_ON_ERROR);
        // The plugins (buildx) normally come from ~/.docker/cli-plugins.
        $home = getenv('HOME') ?: null;
        $config['cliPluginsExtraDirs'] = array_values(array_filter([
            $home !== null ? $home.'/.docker/cli-plugins' : null,
            '/Applications/Docker.app/Contents/Resources/cli-plugins',
            '/usr/local/lib/docker/cli-plugins',
            '/usr/libexec/docker/cli-plugins',
        ]));

        File::put($directory.'/config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        chmod($directory.'/config.json', 0600);

        return $directory;
    }

    /** The daemon of your current Docker context, which a fresh config would not know. */
    private function dockerHost(): string
    {
        $host = getenv('DOCKER_HOST');

        if (is_string($host) && $host !== '') {
            return $host;
        }

        return trim($this->run(
            ['docker', 'context', 'inspect', '--format', '{{.Endpoints.docker.Host}}'],
            'Finding the Docker daemon',
            30,
        )->output());
    }

    /**
     * @param  list<string>  $command
     * @param  ?Closure(string): void  $output
     * @param  array<string, string>  $env
     */
    private function run(array $command, string $what, int $timeout, ?Closure $output = null, ?string $path = null, array $env = []): ProcessResult
    {
        $process = Process::timeout($timeout)->env($env);

        if ($path !== null) {
            $process = $process->path($path);
        }

        $streamed = false;
        $result = $process->run($command, $output === null ? null : function (string $type, string $chunk) use ($output, &$streamed): void {
            $streamed = true;
            $output($chunk);
        });

        // Commands that finish without streaming still get their output logged.
        if ($output !== null && ! $streamed) {
            $output($result->output().$result->errorOutput());
        }

        if ($result->failed()) {
            $lines = preg_split('/\R/', trim($result->errorOutput() ?: $result->output())) ?: [];

            throw new RuntimeException(trim("{$what} failed with exit code {$result->exitCode()}.\n"
                .implode("\n", array_slice($lines, -5))));
        }

        return $result;
    }
}
