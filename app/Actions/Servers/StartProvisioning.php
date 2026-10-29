<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Enums\ProvisioningStatus;
use App\Enums\RunStatus;
use App\Jobs\ProvisionServerJob;
use App\Models\Server;
use App\Models\ServerProvisioningRun;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class StartProvisioning
{
    use AsAction;

    /**
     * Queues a provisioning run. Provisioning is idempotent, so retrying a
     * failed or interrupted run simply starts again from the first step;
     * finished steps report themselves as skipped.
     */
    public function handle(Server $server): ServerProvisioningRun
    {
        if (blank($server->acme_email)) {
            throw new RuntimeException('Set the Let\'s Encrypt email in the server settings first.');
        }

        $active = $server->provisioningRuns()
            ->whereIn('status', [RunStatus::Pending, RunStatus::Running])
            ->latest('id')
            ->first();

        if ($active !== null) {
            if ($active->created_at?->gt(now()->subSeconds(ProvisionServerJob::TIMEOUT))) {
                throw new RuntimeException('This server is already being provisioned.');
            }

            // Older than the job timeout: the worker died or was stopped.
            $active->update([
                'status' => RunStatus::Failed,
                'error' => 'Interrupted: the run never finished.',
                'finished_at' => now(),
            ]);
        }

        $run = $server->provisioningRuns()->create([
            'status' => RunStatus::Pending,
            'steps' => ProvisionServer::initialSteps(),
        ]);

        $server->forceFill(['provisioning_status' => ProvisioningStatus::Provisioning])->save();

        ProvisionServerJob::dispatch($run);

        return $run;
    }
}
