<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Enums\DeploymentTrigger;
use App\Enums\ImageSource;
use App\Models\Site;

/**
 * The steps of a deployment, in order. Every deployment records all of
 * them, so its log shows what was skipped as well as what ran.
 */
final class DeploymentPlan
{
    public const array STEPS = [
        'clone' => 'Clone repository',
        'build' => 'Build and push image',
        'configure' => 'Write configuration',
        'pull' => 'Pull image',
        'migrate' => 'Run migrations',
        'release' => 'Start new containers',
        'verify' => 'Check the site through Traefik',
        'cleanup' => 'Remove old images',
    ];

    public const string REVERT = 'Switch back to the previous image';

    /**
     * @return array<string, bool> step key => whether it runs
     */
    public static function for(Site $site, DeploymentTrigger $trigger): array
    {
        $builds = $trigger === DeploymentTrigger::Manual && $site->image_source === ImageSource::Build;

        return [
            'clone' => $builds,
            'build' => $builds,
            'configure' => true,
            'pull' => true,
            // A redeploy or rollback runs an image whose migrations already ran.
            'migrate' => $trigger === DeploymentTrigger::Manual && $site->run_migrations,
            'release' => true,
            'verify' => true,
            'cleanup' => true,
        ];
    }

    public static function key(string $label): ?string
    {
        $key = array_search($label, self::STEPS, true);

        return is_string($key) ? $key : null;
    }
}
