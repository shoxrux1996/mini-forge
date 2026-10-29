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
        self::ensureServerReady($site);

        $lock = Cache::lock(self::lockName($site), self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new RuntimeException('Another operation is running on this site. Try again when it finishes.');
        }

        try {
            return self::connected($site, $operation);
        } finally {
            $lock->release();
        }
    }

    /**
     * Connects without taking the lock, for callers that already hold it
     * (a deployment holds it across its local build as well).
     *
     * @template T
     *
     * @param  Closure(ComposeManager): T  $operation
     * @return T
     */
    public static function connected(Site $site, Closure $operation): mixed
    {
        $connection = ConnectToServer::run($site->server);

        try {
            return $operation(new ComposeManager($connection, $site));
        } finally {
            $connection->disconnect();
        }
    }

    public static function ensureServerReady(Site $site): void
    {
        if ($site->server->provisioning_status !== ProvisioningStatus::Ready) {
            throw new RuntimeException('Provision the server before managing its sites.');
        }
    }

    public static function lockName(Site $site): string
    {
        return "site:{$site->id}:operation";
    }
}
