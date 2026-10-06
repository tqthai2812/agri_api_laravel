<?php

namespace App\Repositories;

use App\Contracts\Repositories\{
    ProfitReportRepositoryInterface,
    ExpenseRepositoryInterface,
};
use App\Models\Order;
use App\Support\DashboardPeriod;
use Illuminate\Support\LazyCollection;

class ProfitReportRepository implements ProfitReportRepositoryInterface
{
    public function __construct(private ExpenseRepositoryInterface $expenses) {}
    public function orders(DashboardPeriod $p): LazyCollection
    {
        return Order::query()
            ->where("order_status", "completed")
            ->where("completed_at", ">=", $p->storageStart())
            ->where("completed_at", "<", $p->storageEnd())
            ->with(["items", "payments"])
            ->lazyById(200);
    }
    public function expenses(DashboardPeriod $p): LazyCollection
    {
        return $this->expenses
            ->query([
                "date_from" => $p->from,
                "date_to" => $p->to,
                "status" => "posted",
            ])
            ->lazyById(200);
    }
    public function undatedOrders(): LazyCollection
    {
        return Order::query()
            ->where("order_status", "completed")
            ->whereNull("completed_at")
            ->select(["id"])
            ->lazyById(200);
    }
}
