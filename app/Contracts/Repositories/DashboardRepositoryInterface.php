<?php

namespace App\Contracts\Repositories;

use App\Support\DashboardPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

interface DashboardRepositoryInterface
{
    public function createdStatusCounts(DashboardPeriod $period): array;
    public function completedOrders(DashboardPeriod $period): LazyCollection;
    public function recentOrders(DashboardPeriod $period): Collection;
    public function undatedCompletedCount(): int;
}
