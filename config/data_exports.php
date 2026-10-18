<?php
return [
    "disk" => "data_exports",
    "connection" => "data_exports",
    "queue" => "exports",
    "retention_days" => (int) env("DATA_EXPORT_RETENTION_DAYS", 0),
    "max_active_per_user" => 3,
    "reports" => [
        "dashboard_daily" => \App\Exports\Reports\DashboardDailyReport::class,
        "profit_report" => \App\Exports\Reports\ProfitReport::class,
        "inventory_report" => \App\Exports\Reports\InventoryReport::class,
    ],
];
