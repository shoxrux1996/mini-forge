<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Artisan\RunArtisanCommand;
use App\Enums\RunStatus;
use App\Models\ArtisanRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunArtisanCommandJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public ArtisanRun $run) {}

    public function handle(RunArtisanCommand $command): void
    {
        $command->handle($this->run);
    }

    public function failed(?Throwable $e): void
    {
        $run = $this->run->fresh();

        if ($run === null || $run->status->isFinished()) {
            return;
        }

        $run->update([
            'status' => RunStatus::Failed,
            'output' => 'Interrupted: '.($e?->getMessage() ?? 'the job failed'),
            'finished_at' => now(),
        ]);
    }
}
