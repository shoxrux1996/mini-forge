<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Servers\ProvisionServer;
use App\Enums\ProvisioningStatus;
use App\Enums\RunStatus;
use App\Models\ServerProvisioningRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProvisionServerJob implements ShouldQueue
{
    use Queueable;

    /** Seconds; installing Docker on a slow server can take several minutes. */
    public const int TIMEOUT = 1800;

    public int $timeout = self::TIMEOUT;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public ServerProvisioningRun $run) {}

    public function handle(ProvisionServer $provision): void
    {
        $provision->handle($this->run);
    }

    /** Covers a timeout or crash outside the action's own error handling. */
    public function failed(?Throwable $e): void
    {
        $run = $this->run->fresh();

        if ($run === null || $run->status->isFinished()) {
            return;
        }

        $run->update([
            'status' => RunStatus::Failed,
            'error' => 'Interrupted: '.($e?->getMessage() ?? 'the job failed'),
            'finished_at' => now(),
        ]);
        $run->server->forceFill(['provisioning_status' => ProvisioningStatus::Failed])->save();
    }
}
