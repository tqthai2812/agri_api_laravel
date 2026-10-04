<?php

namespace App\Http\Resources;

use App\Support\OrderPaymentState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $code = 'DH' . str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
        $loaded = $this->relationLoaded('payments');
        $payments = $loaded ? $this->payments : collect();
        $payment = $payments->where('status', 'paid')->sortByDesc('id')->first()
            ?? $payments->sortByDesc('id')->first();
        $state = $loaded ? OrderPaymentState::of($this->resource, $payments) : [];
        return [
            'id' => $this->id,
            'invoice_code' => $code,
            'order_code' => $code,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn() => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone_number' => $this->user->phone_number,
            ] : null),
            'note' => $this->note,
            'delivery_id' => $this->delivery_id,
            'delivery_method' => $this->whenLoaded('deliveryMethod', fn() => $this->deliveryMethod ? [
                'id' => $this->deliveryMethod->id,
                'name' => $this->deliveryMethod->name,
                'description' => $this->deliveryMethod->description,
                'base_price' => (float) $this->deliveryMethod->base_price,
                'min_order_amount' => (float) $this->deliveryMethod->min_order_amount,
                'region' => $this->deliveryMethod->region,
            ] : null),
            'discount_id' => $this->discount_id,
            'discount' => $this->whenLoaded('discount', fn() => $this->discount ? [
                'id' => $this->discount->id,
                'discount_code' => $this->discount->discount_code,
                'discount_description' => $this->discount->discount_description,
                'discount_percent' => (int) $this->discount->discount_percent,
                'max_discount_amount' => (float) $this->discount->max_discount_amount,
                'min_order_value' => (float) $this->discount->min_order_value,
            ] : null),
            'discount_amount' => (float) $this->discount_amount,
            'delivery_cost' => (float) $this->delivery_cost,
            'total_quantity' => (int) $this->total_quantity,
            'total_payment' => (float) $this->total_payment,
            'payment_method' => $this->payment_method,
            'payment_method_label' => $this->payment_method === 'VNPAY' ? 'VNPAY' : 'Thanh toán khi nhận hàng',
            'order_status' => $this->order_status,
            'order_status_label' => match ($this->order_status) {
                'pending' => 'Chờ xác nhận',
                'confirmed' => 'Đã xác nhận',
                'shipping' => 'Đang giao',
                'completed' => 'Hoàn thành',
                'cancelled' => 'Đã hủy',
                default => 'Không xác định',
            },
            ...$state,
            'items_count' => $this->whenCounted('items'),
            'address' => new OrderAddressResource($this->whenLoaded('orderAddress')),
            'receiver_address' => new OrderAddressResource($this->whenLoaded('orderAddress')),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'histories' => OrderHistoryResource::collection($this->whenLoaded('histories')),
            'payment' => $this->when($loaded, fn() => $payment ? new PaymentResource($payment) : null),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'completed_at' => $this->completed_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
