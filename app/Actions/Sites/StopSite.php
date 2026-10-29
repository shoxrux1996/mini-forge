<?php

declare(strict_types=1);

namespace App\Actions\Sites;

use App\Enums\SiteStatus;
use App\Infrastructure\Docker\ComposeManager;
use App\Models\Site;
use Lorisleiva\Actions\Concerns\AsAction;

class StopSite
{
    use AsAction;

    /** Stops the containers and keeps them, their volume and data. */
    public function handle(Site $site): Site
    {
        SiteOperation::run($site, function (ComposeManager $manager) use ($site): void {
            $manager->stop();
            $site->markStatus(SiteStatus::Stopped);
        });

        return $site;
    }
}
