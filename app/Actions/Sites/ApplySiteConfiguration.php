<?php

declare(strict_types=1);

namespace App\Actions\Sites;

use App\Enums\SiteStatus;
use App\Infrastructure\Docker\ComposeGenerator;
use App\Infrastructure\Docker\ComposeManager;
use App\Infrastructure\Docker\EnvFile;
use App\Models\Site;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

class ApplySiteConfiguration
{
    use AsAction;

    public function __construct(private readonly ComposeGenerator $compose) {}

    /**
     * Brings the server in line with Mini Forge: directory, database, Redis
     * user, .env and compose.yaml. A deployed site that is not stopped is
     * then recreated with the new configuration.
     */
    public function handle(Site $site): Site
    {
        SiteOperation::run($site, function (ComposeManager $manager) use ($site): void {
            $this->sync($site, $manager);

            if ($site->current_image === null || $site->status === SiteStatus::Stopped) {
                return;
            }

            try {
                $manager->recreate();
            } catch (Throwable $e) {
                $site->markStatus(SiteStatus::Failed);

                throw $e;
            }

            $site->markStatus(SiteStatus::Running);
        });

        return $site;
    }

    /** The file and credential part of applying, shared with deployments. */
    public function sync(Site $site, ComposeManager $manager): void
    {
        $variables = SiteEnvironment::variables($site);

        $manager->prepareDirectory();
        $manager->ensureDatabase((string) ($variables['DB_PASSWORD'] ?? ''));
        $manager->ensureRedisUser((string) ($variables['REDIS_PASSWORD'] ?? ''));
        $manager->writeFiles(EnvFile::render($variables), $this->compose->site($site));
    }
}
