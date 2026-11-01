<?php

declare(strict_types=1);

namespace App\Actions\Sites;

use App\Models\Site;
use Illuminate\Support\Str;

final class SiteEnvironment
{
    /**
     * The variables a new site starts with: Laravel production settings plus
     * credentials for its own Postgres database and Redis user.
     *
     * @return array<string, string>
     */
    public static function defaults(Site $site): array
    {
        return [
            'APP_NAME' => $site->name,
            'APP_ENV' => 'production',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG' => 'false',
            'APP_URL' => 'https://'.$site->domain,
            'LOG_CHANNEL' => 'stderr',
            'LOG_LEVEL' => 'info',

            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'postgres',
            'DB_PORT' => '5432',
            'DB_DATABASE' => $site->databaseName(),
            'DB_USERNAME' => $site->databaseName(),
            'DB_PASSWORD' => Str::password(40, symbols: false),

            'REDIS_CLIENT' => 'phpredis',
            'REDIS_HOST' => 'redis',
            'REDIS_PORT' => '6379',
            'REDIS_USERNAME' => $site->redisUser(),
            'REDIS_PASSWORD' => Str::password(40, symbols: false),
            'REDIS_DB' => (string) $site->redis_database,
            'REDIS_CACHE_DB' => (string) $site->redis_database,
            'REDIS_PREFIX' => $site->redisPrefix(),

            'CACHE_STORE' => 'redis',
            'QUEUE_CONNECTION' => 'redis',
            'SESSION_DRIVER' => 'redis',
            'SESSION_SECURE_COOKIE' => 'true',
        ];
    }

    /**
     * Values that must never appear in stored or displayed output.
     *
     * @return list<string>
     */
    public static function secrets(Site $site): array
    {
        return [
            ...$site->environmentVariables()->where('is_sensitive', true)->get()->pluck('value')->filter()->values()->all(),
            ...($site->registry?->secrets() ?? []),
        ];
    }

    /**
     * @return array<string, ?string>
     */
    public static function variables(Site $site): array
    {
        return $site->environmentVariables()->orderBy('id')->get()->pluck('value', 'key')->all();
    }
}
