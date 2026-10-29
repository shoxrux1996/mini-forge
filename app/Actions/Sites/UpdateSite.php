<?php

declare(strict_types=1);

namespace App\Actions\Sites;

use App\Enums\ServiceRole;
use App\Models\Site;
use Lorisleiva\Actions\Concerns\AsAction;

class UpdateSite
{
    use AsAction;

    /**
     * Saves settings only; they reach the server on the next apply or
     * deploy.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Site $site, array $data): Site
    {
        $site->update($data);

        foreach (['queue_enabled' => ServiceRole::Worker, 'scheduler_enabled' => ServiceRole::Scheduler] as $field => $role) {
            if (array_key_exists($field, $data)) {
                $site->services()->where('role', $role)->update(['enabled' => (bool) $data[$field]]);
            }
        }

        return $site;
    }
}
