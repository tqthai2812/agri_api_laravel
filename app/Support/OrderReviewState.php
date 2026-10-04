<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductReview;

final class OrderReviewState
{
    public static function owns(?Order $order, ?int $userId): bool
    {
        return $order !== null && $userId !== null && (int) $order->user_id === $userId;
    }

    public static function review(OrderItem $item): ?ProductReview
    {
        return $item->relationLoaded('productReview') ? $item->productReview : null;
    }

    public static function canReview(?Order $order, OrderItem $item, ?int $userId): bool
    {
        $package = $item->relationLoaded('package') ? $item->package : null;
        $variant = $package?->relationLoaded('variant') ? $package->variant : null;
        $product = $variant?->relationLoaded('product') ? $variant->product : null;

        return self::owns($order, $userId)
            && $order->order_status === Order::STATUS_COMPLETED
            && $item->relationLoaded('productReview')
            && self::review($item) === null
            && $product !== null;
    }
}
