<?php

namespace App\Providers;

use App\Http\Controllers\Admin\V1\OrderPrintController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class OrderPrintServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'permission:order.view'])
            ->group(function () {
                Route::get('orders/{order}/print', [OrderPrintController::class, 'show'])
                    ->whereNumber('order')
                    ->name('admin.orders.print');
            });
    }
}
