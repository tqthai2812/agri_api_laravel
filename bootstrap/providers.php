<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    App\Providers\OrderStockServiceProvider::class,
    App\Providers\DashboardServiceProvider::class,
    App\Providers\FinanceServiceProvider::class,
];
