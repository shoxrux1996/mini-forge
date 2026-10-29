<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ServerConnector;
use App\Infrastructure\SSH\SshConnector;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ServerConnector::class, SshConnector::class);
    }

    public function boot(): void
    {
        // Compose and config templates rendered for servers.
        View::addNamespace('templates', resource_path('templates'));
    }
}
