<?php

declare(strict_types=1);

namespace App\Actions\Sites;

use App\Enums\ServiceRole;
use App\Infrastructure\Docker\ComposeManager;
use App\Models\Site;
use App\Support\Redactor;
use Lorisleiva\Actions\Concerns\AsAction;

class InspectSiteContainer
{
    use AsAction;

    /**
     * @return array<string, string> label => value, without environment variables
     */
    public function handle(Site $site, ServiceRole $service): array
    {
        $details = SiteOperation::connected($site, fn (ComposeManager $manager) => $manager->inspect($service));
        $redactor = new Redactor(SiteEnvironment::secrets($site));

        return array_map($redactor->redact(...), $details);
    }
}
