<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Every Artisan command Mini Forge can run. There is no free-text input:
 * only these cases ever reach a server.
 */
enum ArtisanCommand: string implements HasLabel
{
    case Migrate = 'migrate';
    case MigrateStatus = 'migrate:status';
    case Optimize = 'optimize';
    case OptimizeClear = 'optimize:clear';
    case ConfigCache = 'config:cache';
    case RouteCache = 'route:cache';
    case ViewCache = 'view:cache';
    case QueueRestart = 'queue:restart';
    case HorizonTerminate = 'horizon:terminate';

    public function getLabel(): string
    {
        return implode(' ', $this->arguments());
    }

    /**
     * @return list<string>
     */
    public function arguments(): array
    {
        return match ($this) {
            self::Migrate => ['migrate', '--force', '--no-interaction'],
            default => [$this->value, '--no-interaction'],
        };
    }

    /** What the confirmation says beyond the command itself. */
    public function warning(): ?string
    {
        return match ($this) {
            self::Migrate => 'Changes the production database. Mini Forge cannot undo migrations.',
            self::QueueRestart, self::HorizonTerminate => 'Workers stop after their current job; Docker starts them again.',
            default => null,
        };
    }
}
