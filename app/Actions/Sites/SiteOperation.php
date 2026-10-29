<?php

declare(strict_types=1);

namespace App\Actions\Sites;

use App\Actions\Servers\ConnectToServer;
use App\Enums\ProvisioningStatus;
use App\Infrastructure\Docker\ComposeManager;
use App\Models\Site;
use Closure;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Runs one remote operation on a site while holding the site's lock, so a
 * deploy, a restart and an environment change never overlap.
 */
final class SiteOperation
{
    public const int LOCK_SECONDS = 1800;

    /**
     * @template T
     *
     * @param  Closure(ComposeManager): T  $operation
     * @return T
     */
    public static function run(Site $site, Closure $operation): mixed
    {
        if ($site->server->provisioning_status !== ProvisioningStatus::Ready) {
            throw new RuntimeException('Provision the server before managing its sites.');
        }

        $lock = Cache::lock(self::lockName($site), self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new RuntimeException('Another operation is running on this site. Try again when it finishes.');
        }

        try {
            $connection = ConnectToServer::run($site->server);

            try {
                return $operation(new ComposeManager($connection, $site));
            } finally {
                $connection->disconnect();
            }
        } finally {
            $lock->release();
        }
    }

    public static function lockName(Site $site): string
    {
        return "site:{$site->id}:operation";
    }
}
