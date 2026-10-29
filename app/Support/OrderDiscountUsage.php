<?php

namespace App\Support;

use App\Models\Discount;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

final class OrderDiscountUsage
{
    /** Caller giữ khóa đơn; gọi đúng trong lần chuyển pending/confirmed -> cancelled. */
    public static function releaseForCancellation(Order $order): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Hoàn lượt voucher phải chạy trong transaction hủy đơn.');
        if (!$order->discount_id || !in_array($order->order_status, ['pending', 'confirmed'], true)) return;
        $discount = Discount::whereKey($order->discount_id)->lockForUpdate()->first();
        if ($discount && (int) $discount->used_count > 0) $discount->decrement('used_count');
        // Giữ discount_id và số tiền snapshot trên đơn để tra cứu lịch sử.
        // Khóa đơn và trạng thái cancelled bảo đảm request lặp không hoàn lượt lần nữa.
    }
}
