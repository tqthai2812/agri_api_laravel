<?php

namespace App\Providers;

use App\Contracts\Repositories\DashboardRepositoryInterface;
use App\Contracts\Services\DashboardServiceInterface;
use App\Repositories\DashboardRepository;
use App\Services\DashboardService;
use Illuminate\Support\ServiceProvider;

class DashboardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DashboardRepositoryInterface::class, DashboardRepository::class);
        $this->app->bind(DashboardServiceInterface::class, DashboardService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/admin-dashboard.php'));
    }
}
