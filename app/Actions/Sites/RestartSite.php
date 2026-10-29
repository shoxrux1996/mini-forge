<?php

declare(strict_types=1);

namespace App\Actions\Sites;

use App\Enums\SiteStatus;
use App\Infrastructure\Docker\ComposeManager;
use App\Models\Site;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

class RestartSite
{
    use AsAction;

    public function handle(Site $site): Site
    {
        if ($site->current_image === null) {
            throw new RuntimeException('Deploy the site before restarting it.');
        }

        SiteOperation::run($site, function (ComposeManager $manager) use ($site): void {
            try {
                $manager->restart();
            } catch (Throwable $e) {
                $site->markStatus(SiteStatus::Failed);

                throw $e;
            }

            $site->markStatus(SiteStatus::Running);
        });

        return $site;
    }
}
