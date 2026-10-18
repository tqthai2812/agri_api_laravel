<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    App\Providers\OrderStockServiceProvider::class,
    App\Providers\DashboardServiceProvider::class,
    App\Providers\FinanceServiceProvider::class,
    App\Providers\InventoryReportServiceProvider::class,
    App\Providers\DataExportServiceProvider::class,
    App\Providers\OrderPrintServiceProvider::class,
];
