<?php

namespace App\Contracts\Services;

use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;

interface OrderStockServiceInterface
{
    public function availability(array $packageIds, bool $lock = false): Collection;

    // Các thao tác dưới đây phải chạy trong transaction.
    // Caller phải khóa dòng orders trước nếu đơn đã tồn tại.
    public function reserve(Order $order): void;

    public function assertReserved(Order $order): void;

    public function release(Order $order): void;

    public function issue(Order $order, int $actorId): void;
}
