<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Actions\Sites\ApplySiteConfiguration;
use App\Actions\Sites\SiteOperation;
use App\Enums\RunStatus;
use App\Enums\SiteStatus;
use App\Exceptions\DeploymentStepFailed;
use App\Exceptions\RemoteCommandFailed;
use App\Infrastructure\Build\ImageBuilder;
use App\Infrastructure\Docker\ComposeManager;
use App\Jobs\DeploySiteJob;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\Site;
use App\Support\Redactor;
use App\Support\StepOutput;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Runs a deployment recorded by StartDeployment:
 *
 *   clone → build and push (on this machine) → write configuration → pull
 *   → migrate → start new containers → check through Traefik → clean up
 *
 * Until "start new containers" the old release keeps serving. If that step
 * or the check fails, the previous image is started again. Migrations are
 * never reverted.
 */
class DeploySite
{
    use AsAction;

    /** Old images kept on the server besides the running one. */
    public const int KEEP_IMAGES = 3;

    /** Live output is saved at most this often while a step runs. */
    private const float SAVE_EVERY_SECONDS = 2.0;

    private Deployment $deployment;

    private Redactor $redactor;

    /** @var array<string, DeploymentStep> */
    private array $steps = [];

    public function __construct(
        private readonly ImageBuilder $builder,
        private readonly ApplySiteConfiguration $configuration,
    ) {}

    public function handle(Deployment $deployment): Deployment
    {
        $this->deployment = $deployment;
        $site = $deployment->site;
        $this->redactor = new Redactor($this->secrets($site));

        foreach ($deployment->steps()->get() as $step) {
            if (($key = DeploymentPlan::key($step->name)) !== null) {
                $this->steps[$key] = $step;
            }
        }

        // The same lock as restarts and applies, held across the local build.
        $lock = Cache::lock(SiteOperation::lockName($site), DeploySiteJob::TIMEOUT);

        try {
            $lock->block(300);
        } catch (LockTimeoutException) {
            $this->finish(RunStatus::Failed, 'Another operation on this site did not finish within 5 minutes.');

            return $deployment;
        }

        $previousStatus = $site->status;
        $workspace = storage_path('app/private/builds/'.$deployment->id);
        $deployment->update(['status' => RunStatus::Running, 'started_at' => now()]);
        $site->markStatus(SiteStatus::Deploying);

        try {
            $this->deploy($site, $workspace, $previousStatus);
            $this->finish(RunStatus::Succeeded);
        } catch (Throwable $e) {
            if ($site->refresh()->status === SiteStatus::Deploying) {
                $site->markStatus($previousStatus);
            }

            $this->finish(RunStatus::Failed, $e->getMessage());
        } finally {
            File::deleteDirectory($workspace);
            $lock->release();
        }

        return $deployment;
    }

    private function deploy(Site $site, string $workspace, SiteStatus $previousStatus): void
    {
        if ($this->runs('clone')) {
            $sha = $this->step('clone', fn (Closure $out) => $this->builder->clone(
                $site, (string) $this->deployment->branch, $workspace, $out,
            ));

            $this->deployment->update([
                'commit_sha' => $sha,
                'image' => $site->image_repository.':'.substr($sha, 0, 12).'-'.$this->deployment->id,
            ]);

            $this->step('build', function (Closure $out) use ($site, $workspace): void {
                $out($this->builder->prepareContext($workspace));
                $out("Building {$this->deployment->image}\n");
                $this->builder->buildAndPush(
                    $site, $workspace, (string) $this->deployment->image,
                    ImageBuilder::platform($site->server->architecture), $out,
                );
            });
        }

        $image = (string) $this->deployment->image;

        SiteOperation::connected($site, function (ComposeManager $manager) use ($site, $image, $previousStatus): void {
            $released = false;

            try {
                $this->step('configure', function (Closure $out) use ($site, $manager, $image): void {
                    $this->configuration->sync($site, $manager, $image);
                    $out("Wrote .env and compose.yaml for {$image}.\n");
                });
                $this->step('pull', fn (Closure $out) => $out(self::lines($manager->pull($image, $site->registry))));

                if ($this->runs('migrate')) {
                    $this->step('migrate', fn (Closure $out) => $out(self::lines($manager->migrate())));
                }

                $released = true;
                $this->step('release', fn (Closure $out) => $out(self::lines($manager->recreate())));
                $this->step('verify', fn (Closure $out) => $out(self::lines($manager->verifyRoute())));
            } catch (DeploymentStepFailed $e) {
                $this->switchBack($site, $manager, $released, $previousStatus);

                throw $e;
            }

            $site->forceFill(['current_image' => $image, 'status' => SiteStatus::Running])->save();

            try {
                $this->step('cleanup', fn (Closure $out) => $out(self::lines($manager->removeImagesExcept($this->imagesToKeep($site)))));
            } catch (DeploymentStepFailed) {
                // The release is live; a leftover image only costs disk space.
            }
        });
    }

    /**
     * Restores the previous release's files and, if new containers were
     * already started, the previous containers. A first deployment has
     * nothing to switch back to and is left as it is for inspection.
     */
    private function switchBack(Site $site, ComposeManager $manager, bool $released, SiteStatus $previousStatus): void
    {
        if ($site->current_image === null) {
            if ($released) {
                $site->markStatus(SiteStatus::Failed);
            }

            return;
        }

        $step = $this->deployment->steps()->create([
            'position' => (int) $this->deployment->steps()->max('position') + 1,
            'name' => DeploymentPlan::REVERT,
            'status' => RunStatus::Pending,
        ]);
        $this->steps['revert'] = $step;

        try {
            $this->step('revert', function (Closure $out) use ($site, $manager, $released, $previousStatus): void {
                // current_image is still the previous release.
                $this->configuration->sync($site, $manager);
                $out("Restored .env and compose.yaml for {$site->current_image}.\n");

                if ($released && $previousStatus !== SiteStatus::Stopped) {
                    $out(self::lines($manager->recreate()));
                } elseif ($released) {
                    $manager->stop();
                    $out("Stopped again, as before the deployment.\n");
                }
            });
        } catch (DeploymentStepFailed) {
            $site->markStatus(SiteStatus::Failed);

            return;
        }

        $site->markStatus($released ? ($previousStatus === SiteStatus::Stopped ? SiteStatus::Stopped : SiteStatus::Running) : $previousStatus);
    }

    /**
     * Runs one step, saving its output live. Closures receive an $out
     * callback; output is redacted and trimmed before it is stored.
     *
     * @template T
     *
     * @param  Closure(Closure(string): void): T  $work
     * @return T
     */
    private function step(string $key, Closure $work): mixed
    {
        $step = $this->steps[$key];
        $step->update(['status' => RunStatus::Running, 'started_at' => now(), 'output' => null]);

        $buffer = '';
        $savedAt = microtime(true);
        $out = function (string $chunk) use ($step, &$buffer, &$savedAt): void {
            $buffer .= $chunk;

            if (microtime(true) - $savedAt >= self::SAVE_EVERY_SECONDS) {
                $step->update(['output' => $this->clean($buffer)]);
                $savedAt = microtime(true);
            }
        };

        try {
            $result = $work($out);
        } catch (Throwable $e) {
            $detail = $e instanceof RemoteCommandFailed && $e->result !== null ? $e->result->combinedOutput() : '';

            $step->update([
                'status' => RunStatus::Failed,
                'output' => $this->clean(implode("\n", array_filter([trim($buffer), trim($detail), $e->getMessage()]))),
                'finished_at' => now(),
            ]);

            throw DeploymentStepFailed::from($step->name, $this->redactor->redact($e->getMessage()), $e);
        }

        $step->update(['status' => RunStatus::Succeeded, 'output' => $this->clean($buffer), 'finished_at' => now()]);

        return $result;
    }

    private function runs(string $key): bool
    {
        return isset($this->steps[$key]) && $this->steps[$key]->status === RunStatus::Pending;
    }

    private function finish(RunStatus $status, ?string $error = null): void
    {
        // Steps that never ran because an earlier one failed.
        $this->deployment->steps()->where('status', RunStatus::Pending)->update(['status' => RunStatus::Skipped]);

        $this->deployment->update([
            'status' => $status,
            'error' => $error === null ? null : mb_strimwidth($this->redactor->redact($error), 0, 2000, '…'),
            'finished_at' => now(),
        ]);
    }

    /**
     * The running image, the one it replaced and those of the last few
     * successful deployments stay on the server for quick rollbacks.
     *
     * @return list<string>
     */
    private function imagesToKeep(Site $site): array
    {
        $recent = $site->deployments()
            ->where('status', RunStatus::Succeeded)
            ->whereNotNull('image')
            ->latest('id')
            ->limit(self::KEEP_IMAGES)
            ->pluck('image')
            ->all();

        return array_values(array_unique(array_filter([
            (string) $this->deployment->image,
            $this->deployment->previous_image,
            ...$recent,
        ])));
    }

    /**
     * @return list<string>
     */
    private function secrets(Site $site): array
    {
        return [
            ...$site->environmentVariables()->where('is_sensitive', true)->get()->pluck('value')->filter()->all(),
            ...($site->registry?->secrets() ?? []),
        ];
    }

    private static function lines(string $output): string
    {
        return rtrim($output)."\n";
    }

    private function clean(string $output): string
    {
        return StepOutput::tail($this->redactor->redact($output));
    }
}
