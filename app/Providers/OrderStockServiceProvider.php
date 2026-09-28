<?php

namespace App\Providers;

use App\Contracts\Repositories\OrderStockRepositoryInterface;
use App\Contracts\Services\OrderStockServiceInterface;
use App\Repositories\OrderStockRepository;
use App\Services\OrderStockService;
use Illuminate\Support\ServiceProvider;

class OrderStockServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            OrderStockRepositoryInterface::class,
            OrderStockRepository::class
        );

        $this->app->bind(
            OrderStockServiceInterface::class,
            OrderStockService::class
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(
            base_path('routes/order-payment.php')
        );
    }
}
