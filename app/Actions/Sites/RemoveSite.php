<?php

declare(strict_types=1);

namespace App\Actions\Sites;

use App\Enums\ProvisioningStatus;
use App\Infrastructure\Docker\ComposeManager;
use App\Models\Site;
use Lorisleiva\Actions\Concerns\AsAction;

class RemoveSite
{
    use AsAction;

    /**
     * Removes the site's containers and files from the server, then the
     * record. With $deleteData the Postgres database and storage volume go
     * too; without it they stay on the server.
     */
    public function handle(Site $site, bool $deleteData): void
    {
        // A site on a server that was never provisioned has nothing remote.
        if ($site->server->provisioning_status === ProvisioningStatus::Ready) {
            SiteOperation::run($site, fn (ComposeManager $manager) => $manager->remove($deleteData));
        }

        $site->delete();
    }
}
