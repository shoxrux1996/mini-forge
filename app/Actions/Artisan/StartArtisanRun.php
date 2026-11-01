<?php

declare(strict_types=1);

namespace App\Actions\Artisan;

use App\Actions\Sites\SiteOperation;
use App\Enums\ArtisanCommand;
use App\Enums\SiteStatus;
use App\Jobs\RunArtisanCommandJob;
use App\Models\ArtisanRun;
use App\Models\Site;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class StartArtisanRun
{
    use AsAction;

    /** Records the run and queues it; its output appears in the site's Commands tab. */
    public function handle(Site $site, ArtisanCommand $command, ?User $user = null): ArtisanRun
    {
        SiteOperation::ensureServerReady($site);

        if ($site->status !== SiteStatus::Running) {
            throw new RuntimeException('Artisan commands run in the web container, so the site must be running.');
        }

        $run = $site->artisanRuns()->create(['user_id' => $user?->id, 'command' => $command]);

        RunArtisanCommandJob::dispatch($run);

        return $run;
    }
}
