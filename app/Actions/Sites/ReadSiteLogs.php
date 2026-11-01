<?php

declare(strict_types=1);

namespace App\Actions\Sites;

use App\Enums\ServiceRole;
use App\Infrastructure\Docker\ComposeManager;
use App\Models\Site;
use App\Support\Redactor;
use Lorisleiva\Actions\Concerns\AsAction;

class ReadSiteLogs
{
    use AsAction;

    public const array LINE_CHOICES = [100, 500, 2000];

    /** Read-only, so it does not wait for the site lock. Secrets are masked. */
    public function handle(Site $site, ServiceRole $service, int $lines = 100): string
    {
        $output = SiteOperation::connected($site, fn (ComposeManager $manager) => $manager->logs($service, $lines));

        return (new Redactor(SiteEnvironment::secrets($site)))->redact($output);
    }
}
