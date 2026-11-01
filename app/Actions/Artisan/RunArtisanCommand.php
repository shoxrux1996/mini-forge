<?php

declare(strict_types=1);

namespace App\Actions\Artisan;

use App\Actions\Sites\SiteEnvironment;
use App\Actions\Sites\SiteOperation;
use App\Data\CommandResult;
use App\Enums\RunStatus;
use App\Infrastructure\Docker\ComposeManager;
use App\Models\ArtisanRun;
use App\Support\Redactor;
use App\Support\StepOutput;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class RunArtisanCommand
{
    use AsAction;

    /**
     * Runs under the site lock, so it never overlaps a deployment. A failure
     * of the command itself or of the connection ends up in the run's output.
     */
    public function handle(ArtisanRun $run): ArtisanRun
    {
        $site = $run->site;
        $redactor = new Redactor(SiteEnvironment::secrets($site));

        $run->update(['status' => RunStatus::Running, 'started_at' => now()]);

        try {
            /** @var CommandResult $result */
            $result = SiteOperation::run($site, fn (ComposeManager $manager) => $manager->artisan($run->command));

            $run->update([
                'status' => $result->successful() ? RunStatus::Succeeded : RunStatus::Failed,
                'exit_code' => $result->exitCode,
                'output' => StepOutput::tail($redactor->redact($result->combinedOutput())),
                'finished_at' => now(),
            ]);
        } catch (RuntimeException $e) {
            $run->update([
                'status' => RunStatus::Failed,
                'output' => $redactor->redact($e->getMessage()),
                'finished_at' => now(),
            ]);
        }

        return $run;
    }
}
