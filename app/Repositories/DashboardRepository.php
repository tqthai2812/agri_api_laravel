<?php

namespace App\Repositories;

use App\Contracts\Repositories\DashboardRepositoryInterface;
use App\Models\Order;
use App\Support\DashboardPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

class DashboardRepository implements DashboardRepositoryInterface
{
    private function inPeriod(Builder $query, string $column, DashboardPeriod $period): Builder
    {
        // Half-open bounds include the full last day, without wrapping indexed columns in DATE().
        return $query->where($column, '>=', $period->storageStart())
            ->where($column, '<', $period->storageEnd());
    }

    public function createdStatusCounts(DashboardPeriod $period): array
    {
        return $this->inPeriod(Order::query(), 'created_at', $period)
            ->selectRaw('order_status, COUNT(*) AS aggregate')
            ->groupBy('order_status')->pluck('aggregate', 'order_status')
            ->map(fn($count) => (int) $count)->all();
    }

    public function completedOrders(DashboardPeriod $period): LazyCollection
    {
        return $this->inPeriod(
            Order::query()->where('order_status', Order::STATUS_COMPLETED),
            'completed_at',
            $period
        )->select([
            'id',
            'order_status',
            'payment_method',
            'payment_review',
            'payment_expires_at',
            'discount_amount',
            'delivery_cost',
            'total_payment',
            'total_quantity',
            'completed_at',
        ])->with([
            'items:id,order_id,package_id,quantity,price,product_name,variant_name,sku,size,unit,discount_amount,net_sales_amount,cost_total',
            'items.package:id,variant_id',
            'items.package.variant:id,product_id',
            'items.package.variant.product:id,product_name',
            // Read every payment attempt. A pending/failed retry must not hide an earlier paid receipt.
            'payments:id,order_id,payment_method,amount,status,paid_at',
        ])->lazyById(200);
    }

    public function recentOrders(DashboardPeriod $period): Collection
    {
        return $this->inPeriod(Order::query(), 'created_at', $period)
            ->select(['id', 'order_status', 'payment_method', 'total_payment', 'created_at'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get();
    }

    public function undatedCompletedCount(): int
    {
        return Order::query()->where('order_status', Order::STATUS_COMPLETED)
            ->whereNull('completed_at')->count();
    }
}
