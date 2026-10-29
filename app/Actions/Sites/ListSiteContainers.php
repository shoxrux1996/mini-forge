<?php

declare(strict_types=1);

namespace App\Actions\Sites;

use App\Actions\Servers\ConnectToServer;
use App\Infrastructure\Docker\ComposeManager;
use App\Models\Site;
use Lorisleiva\Actions\Concerns\AsAction;

class ListSiteContainers
{
    use AsAction;

    /**
     * Read-only, so it does not wait for the site lock.
     *
     * @return list<array{name: string, service: string, state: string, health: string, status: string, image: string}>
     */
    public function handle(Site $site): array
    {
        $connection = ConnectToServer::run($site->server);

        try {
            return (new ComposeManager($connection, $site))->containers();
        } finally {
            $connection->disconnect();
        }
    }
}
