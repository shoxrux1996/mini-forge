<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\RunStatus;
use App\Enums\SiteStatus;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $servers = Server::count();
        $reachable = Server::where('last_connected_at', '>', now()->subMinutes(Server::REACHABLE_FOR_MINUTES))->count();
        $failed = Deployment::where('status', RunStatus::Failed)->where('created_at', '>', now()->subDays(7))->count();

        return [
            Stat::make('Servers', $servers)
                ->description("{$reachable} reached in the last ".Server::REACHABLE_FOR_MINUTES.' min'),
            Stat::make('Sites', Site::count())
                ->description(Site::where('status', SiteStatus::Running)->count().' running at last deploy'),
            Stat::make('Failed deployments', $failed)
                ->description('Last 7 days')
                ->color($failed > 0 ? 'danger' : 'gray'),
        ];
    }
}
