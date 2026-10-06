<?php

namespace App\Contracts\Services;

use App\Support\DashboardPeriod;

interface DashboardServiceInterface
{
    public function overview(DashboardPeriod $period): array;
}
