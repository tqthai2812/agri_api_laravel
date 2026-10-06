<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            \App\Contracts\Repositories\ExpenseRepositoryInterface::class,
            \App\Repositories\ExpenseRepository::class,
        );
        $this->app->bind(
            \App\Contracts\Services\ExpenseServiceInterface::class,
            \App\Services\ExpenseService::class,
        );
        $this->app->bind(
            \App\Contracts\Repositories\ProfitReportRepositoryInterface::class,
            \App\Repositories\ProfitReportRepository::class,
        );
        $this->app->bind(
            \App\Contracts\Services\ProfitReportServiceInterface::class,
            \App\Services\ProfitReportService::class,
        );
    }
    public function boot(): void
    {
        $this->loadRoutesFrom(base_path("routes/admin-finance.php"));
    }
}
