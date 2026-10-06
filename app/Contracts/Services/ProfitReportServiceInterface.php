<?php

namespace App\Contracts\Services;

use App\Support\DashboardPeriod;

interface ProfitReportServiceInterface
{
    public function report(
        DashboardPeriod $p,
        array $pages = [],
        bool $export = false,
    ): array;
}
