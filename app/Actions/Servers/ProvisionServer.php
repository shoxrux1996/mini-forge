<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Contracts\ServerConnection;
use App\Data\CommandResult;
use App\Enums\ProvisioningStatus;
use App\Enums\RunStatus;
use App\Exceptions\RemoteCommandFailed;
use App\Infrastructure\Docker\ComposeGenerator;
use App\Infrastructure\SSH\Shell;
use App\Models\ServerProvisioningRun;
use App\Support\StepOutput;
use Closure;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

class ProvisionServer
{
    use AsAction;

    /** A step whose output starts with this was already done. */
    private const string SKIPPED = 'SKIPPED: ';

    /**
     * key => [label, timeout in seconds]. Each step checks before it changes
     * anything, so the whole list is safe to run again.
     */
    private const array STEPS = [
        'check-access' => ['Check root access', 30],
        'check-system' => ['Check operating system', 30],
        'check-ports' => ['Check ports 80 and 443', 30],
        'install-docker' => ['Install Docker Engine', 900],
        'install-compose' => ['Install Docker Compose plugin', 300],
        'enable-docker' => ['Start Docker at boot', 60],
        'create-directories' => ['Create Mini Forge directories', 30],
        'create-network' => ['Create shared proxy network', 30],
        'write-traefik' => ['Write Traefik configuration', 60],
        'start-traefik' => ['Start Traefik', 300],
        'verify' => ['Verify Docker, Compose and Traefik', 60],
    ];

    public function __construct(private readonly ComposeGenerator $compose) {}

    /**
     * @return list<array{key: string, label: string, status: string, output: ?string, started_at: ?string, finished_at: ?string}>
     */
    public static function initialSteps(): array
    {
        return collect(self::STEPS)
            ->map(fn (array $step, string $key) => [
                'key' => $key,
                'label' => $step[0],
                'status' => RunStatus::Pending->value,
                'output' => null,
                'started_at' => null,
                'finished_at' => null,
            ])
            ->values()
            ->all();
    }

    public function handle(ServerProvisioningRun $run): void
    {
        $server = $run->server;
        $lock = Cache::lock("server:{$server->id}:provisioning", 3600);

        if (! $lock->get()) {
            $this->finish($run, RunStatus::Failed, 'Another provisioning run holds the lock for this server.');

            return;
        }

        $run->update(['status' => RunStatus::Running, 'started_at' => now(), 'steps' => self::initialSteps()]);
        $connection = null;

        try {
            $connection = ConnectToServer::run($server);

            foreach (array_keys(self::STEPS) as $index => $key) {
                $this->runStep($run, $index, fn () => $this->execute($key, $run, $connection));
            }

            $this->finish($run, RunStatus::Succeeded);
        } catch (Throwable $e) {
            $this->finish($run, RunStatus::Failed, $e->getMessage());
        } finally {
            $connection?->disconnect();
            $lock->release();
        }
    }

    /**
     * @param  Closure(): string  $callback  returns the step output
     */
    private function runStep(ServerProvisioningRun $run, int $index, Closure $callback): void
    {
        $this->updateStep($run, $index, ['status' => RunStatus::Running->value, 'started_at' => now()->toIso8601String()]);

        try {
            $output = $callback();
        } catch (Throwable $e) {
            // Keep the whole command output for the log, not just the summary.
            $output = $e instanceof RemoteCommandFailed && $e->result !== null
                ? $e->result->combinedOutput()
                : $e->getMessage();

            $this->updateStep($run, $index, [
                'status' => RunStatus::Failed->value,
                'output' => StepOutput::tail($output),
                'finished_at' => now()->toIso8601String(),
            ]);

            throw $e;
        }

        $skipped = str_starts_with($output, self::SKIPPED);

        $this->updateStep($run, $index, [
            'status' => ($skipped ? RunStatus::Skipped : RunStatus::Succeeded)->value,
            'output' => StepOutput::tail($skipped ? substr($output, strlen(self::SKIPPED)) : $output),
            'finished_at' => now()->toIso8601String(),
        ]);
    }

    private function execute(string $key, ServerProvisioningRun $run, ServerConnection $connection): string
    {
        $label = self::STEPS[$key][0];

        return match ($key) {
            'write-traefik' => $this->writeTraefik($run, $connection),
            'verify' => $this->verify($run, $connection),
            default => $this->script($connection, $key, self::STEPS[$key][1])->throw($label)->combinedOutput(),
        };
    }

    private function writeTraefik(ServerProvisioningRun $run, ServerConnection $connection): string
    {
        $path = config('mini-forge.remote_root').'/infrastructure/compose.yaml';
        $connection->upload($path, $this->compose->infrastructure($run->server), 0640);

        $validation = $this->script($connection, 'validate-traefik', 60)->throw('Validating compose.yaml');

        return "Wrote {$path}\n".$validation->combinedOutput();
    }

    private function verify(ServerProvisioningRun $run, ServerConnection $connection): string
    {
        $server = InspectServer::run($run->server, $connection);

        if (blank($server->docker_version) || blank($server->compose_version)) {
            throw new RuntimeException('Docker or Docker Compose is not responding after installation.');
        }

        $traefik = $connection->run(
            "docker inspect -f '{{.State.Health.Status}}' mini-forge-traefik",
            30,
        )->throw('Checking Traefik');

        if (trim($traefik->output) !== 'healthy') {
            throw new RuntimeException('Traefik is not healthy: '.trim($traefik->output));
        }

        return "Docker {$server->docker_version}, Compose {$server->compose_version}, Traefik healthy";
    }

    private function script(ServerConnection $connection, string $name, int $timeout): CommandResult
    {
        $script = file_get_contents(resource_path("scripts/provisioning/{$name}.sh"));

        if ($script === false) {
            throw new RuntimeException("Missing provisioning script [{$name}].");
        }

        $variables = Shell::exports([
            'ROOT' => (string) config('mini-forge.remote_root'),
            'NETWORK' => (string) config('mini-forge.network'),
        ]);

        return $connection->run($variables."\n".$script, $timeout);
    }

    /**
     * @param  array<string, string|null>  $changes
     */
    private function updateStep(ServerProvisioningRun $run, int $index, array $changes): void
    {
        $steps = $run->steps ?? self::initialSteps();
        $steps[$index] = array_merge($steps[$index], $changes);
        $run->update(['steps' => $steps]);
    }

    private function finish(ServerProvisioningRun $run, RunStatus $status, ?string $error = null): void
    {
        $run->update(['status' => $status, 'error' => $error, 'finished_at' => now()]);

        $run->server->forceFill([
            'provisioning_status' => $status === RunStatus::Succeeded ? ProvisioningStatus::Ready : ProvisioningStatus::Failed,
        ])->save();
    }
}
