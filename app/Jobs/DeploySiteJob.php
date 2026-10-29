<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Deployments\DeploySite;
use App\Enums\RunStatus;
use App\Enums\SiteStatus;
use App\Models\Deployment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DeploySiteJob implements ShouldQueue
{
    use Queueable;

    /** Seconds; a cross-architecture build under emulation can be slow. */
    public const int TIMEOUT = 3600;

    public int $timeout = self::TIMEOUT;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public Deployment $deployment) {}

    public function handle(DeploySite $deploy): void
    {
        $deploy->handle($this->deployment);
    }

    /** Covers a timeout or crash outside the action's own error handling. */
    public function failed(?Throwable $e): void
    {
        $deployment = $this->deployment->fresh();

        if ($deployment === null || $deployment->status->isFinished()) {
            return;
        }

        $deployment->update([
            'status' => RunStatus::Failed,
            'error' => 'Interrupted: '.($e?->getMessage() ?? 'the job failed'),
            'finished_at' => now(),
        ]);
        $deployment->steps()->whereIn('status', [RunStatus::Pending, RunStatus::Running])->update(['status' => RunStatus::Skipped]);

        if ($deployment->site->status === SiteStatus::Deploying) {
            $deployment->site->markStatus(SiteStatus::Failed);
        }
    }
}
