<?php

namespace App\Contracts\Repositories;

use App\Support\DashboardPeriod;
use Illuminate\Support\LazyCollection;

interface ProfitReportRepositoryInterface
{
    public function orders(DashboardPeriod $p): LazyCollection;
    public function expenses(DashboardPeriod $p): LazyCollection;
    public function undatedOrders(): LazyCollection;
}
