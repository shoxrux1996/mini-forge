<?php

declare(strict_types=1);

namespace App\Actions\Sites;

use App\Enums\ServiceRole;
use App\Models\Site;
use App\Models\SiteEnvironmentVariable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class CreateSite
{
    use AsAction;

    /**
     * Creates the site record with its services and a ready-to-use
     * environment. Nothing changes on the server until it is applied or
     * deployed.
     *
     * @param  array<string, mixed>  $data  site attributes plus queue_enabled and scheduler_enabled
     */
    public function handle(array $data): Site
    {
        return DB::transaction(function () use ($data): Site {
            $site = Site::create($data);

            $site->services()->createMany([
                ['role' => ServiceRole::Web, 'enabled' => true],
                ['role' => ServiceRole::Worker, 'enabled' => (bool) ($data['queue_enabled'] ?? true)],
                ['role' => ServiceRole::Scheduler, 'enabled' => (bool) ($data['scheduler_enabled'] ?? true)],
            ]);

            foreach (SiteEnvironment::defaults($site) as $key => $value) {
                $site->environmentVariables()->create([
                    'key' => $key,
                    'value' => $value,
                    'is_sensitive' => SiteEnvironmentVariable::looksSensitive($key),
                ]);
            }

            return $site;
        });
    }
}
