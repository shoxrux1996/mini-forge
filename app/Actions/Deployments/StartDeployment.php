<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Actions\Sites\SiteOperation;
use App\Enums\DeploymentTrigger;
use App\Enums\ImageSource;
use App\Enums\RunStatus;
use App\Infrastructure\Build\ImageBuilder;
use App\Jobs\DeploySiteJob;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class StartDeployment
{
    use AsAction;

    /** A tag Docker accepts. */
    public const string TAG_PATTERN = '~^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$~';

    /**
     * Records a deployment with all its steps and queues it.
     *
     * - manual: builds the branch (or, for a prebuilt image, deploys $tag)
     * - redeploy: releases the running image again
     * - rollback: releases the image of an earlier successful deployment
     */
    public function handle(
        Site $site,
        DeploymentTrigger $trigger = DeploymentTrigger::Manual,
        ?User $user = null,
        ?string $tag = null,
        ?Deployment $rollbackTo = null,
    ): Deployment {
        SiteOperation::ensureServerReady($site);

        if ($site->registry !== null && ! $site->registry->serves($site->image_repository)) {
            throw new RuntimeException("The registry {$site->registry->host} does not host {$site->image_repository}.");
        }

        $image = $this->image($site, $trigger, $tag, $rollbackTo);

        if ($trigger === DeploymentTrigger::Manual && $site->image_source === ImageSource::Build) {
            ImageBuilder::platform($site->server->architecture);
        }

        return DB::transaction(function () use ($site, $trigger, $user, $image): Deployment {
            $this->ensureNoneActive($site);

            $deployment = $site->deployments()->create([
                'user_id' => $user?->id,
                'trigger' => $trigger,
                'status' => RunStatus::Pending,
                'branch' => $image === null ? $site->branch : null,
                'image' => $image,
                'previous_image' => $site->current_image,
            ]);

            $position = 0;
            foreach (DeploymentPlan::for($site, $trigger) as $key => $runs) {
                $deployment->steps()->create([
                    'position' => ++$position,
                    'name' => DeploymentPlan::STEPS[$key],
                    'status' => $runs ? RunStatus::Pending : RunStatus::Skipped,
                ]);
            }

            DeploySiteJob::dispatch($deployment)->afterCommit();

            return $deployment;
        });
    }

    /** The image to release, or null when it is built from the branch. */
    private function image(Site $site, DeploymentTrigger $trigger, ?string $tag, ?Deployment $rollbackTo): ?string
    {
        return match ($trigger) {
            DeploymentTrigger::Manual => $site->image_source === ImageSource::Build ? null : $this->tagged($site, $tag),
            DeploymentTrigger::Redeploy => $site->current_image
                ?? throw new RuntimeException('The site has not been deployed yet.'),
            DeploymentTrigger::Rollback => $this->rollbackImage($site, $rollbackTo),
        };
    }

    private function tagged(Site $site, ?string $tag): string
    {
        if ($tag === null || preg_match(self::TAG_PATTERN, $tag) !== 1) {
            throw new RuntimeException('Give the image tag to deploy, like v1.4.0 or a commit SHA.');
        }

        return $site->image_repository.':'.$tag;
    }

    private function rollbackImage(Site $site, ?Deployment $target): string
    {
        if ($target === null || $target->site_id !== $site->id || $target->status !== RunStatus::Succeeded || $target->image === null) {
            throw new RuntimeException('Only a successful deployment of this site can be rolled back to.');
        }

        if ($target->image === $site->current_image) {
            throw new RuntimeException('That image is already running.');
        }

        return $target->image;
    }

    private function ensureNoneActive(Site $site): void
    {
        $active = $site->deployments()
            ->whereIn('status', [RunStatus::Pending, RunStatus::Running])
            ->lockForUpdate()
            ->latest('id')
            ->first();

        if ($active === null) {
            return;
        }

        if ($active->created_at?->gt(now()->subSeconds(DeploySiteJob::TIMEOUT))) {
            throw new RuntimeException('A deployment of this site is already in progress.');
        }

        // Older than the job timeout: the worker died or was stopped.
        $active->update([
            'status' => RunStatus::Failed,
            'error' => 'Interrupted: the deployment never finished.',
            'finished_at' => now(),
        ]);
        $active->steps()->whereIn('status', [RunStatus::Pending, RunStatus::Running])->update(['status' => RunStatus::Skipped]);
    }
}
