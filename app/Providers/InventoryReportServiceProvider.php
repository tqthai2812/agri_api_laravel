<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class InventoryReportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            \App\Contracts\Repositories\InventoryReportRepositoryInterface::class,
            \App\Repositories\InventoryReportRepository::class,
        );
        $this->app->bind(
            \App\Contracts\Services\InventoryReportServiceInterface::class,
            \App\Services\InventoryReportService::class,
        );
    }
    public function boot(): void
    {
        $this->loadRoutesFrom(base_path("routes/admin-inventory-report.php"));
    }
}
