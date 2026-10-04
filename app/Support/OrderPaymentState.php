<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class OrderPaymentState
{
    public static function deadline(Order $order): ?Carbon
    {
        return $order->payment_expires_at
            ? Carbon::parse($order->payment_expires_at, config('app.timezone'))
            : null;
    }

    public static function of(Order $order, Collection $payments): array
    {
        $paid = $payments->where('status', 'paid');
        $review = (bool) $order->payment_review;
        $online = $order->payment_method === 'VNPAY';
        $attempts = $payments->where('payment_method', 'VNPAY');
        $deadline = self::deadline($order);
        $validPaid = $paid->count() === 1
            && $paid->first()->payment_method === $order->payment_method
            && OrderMoney::cents($paid->first()->amount) === OrderMoney::cents($order->total_payment);
        $settledUnpaid = $attempts->isNotEmpty()
            && $attempts->every(fn($p) => in_array($p->status, ['failed', 'expired'], true));
        $canCancel = in_array($order->order_status, ['pending', 'confirmed'], true)
            && !$review && $paid->isEmpty() && (!$online || $settledUnpaid);
        $canPay = $online && $order->order_status === 'pending'
            && !$review && $paid->isEmpty() && $deadline?->isFuture();
        $next = match ($order->order_status) {
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['shipping', 'cancelled'],
            'shipping' => ['completed'],
            default => [],
        };
        $next = array_values(array_filter($next, fn($status) => !$review
            && ($status !== 'cancelled' || $canCancel)
            && (!($online && in_array($status, ['confirmed', 'shipping', 'completed'], true)) || $validPaid)));
        return [
            'is_paid' => $paid->isNotEmpty(),
            'payment_review' => $order->payment_review,
            'payment_expires_at' => $deadline?->toIso8601String(),
            'can_pay' => (bool) $canPay,
            'can_cancel' => $canCancel,
            'allowed_next_statuses' => $next,
        ];
    }
}
