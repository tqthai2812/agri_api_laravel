<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_code' => 'DH' . str_pad($this->id, 6, '0', STR_PAD_LEFT),

            'user_id' => $this->user_id,

            'user' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone_number' => $this->user->phone_number,
            ] : null,

            'note' => $this->note,

            'delivery_id' => $this->delivery_id,
            'delivery_method' => $this->deliveryMethod ? [
                'id' => $this->deliveryMethod->id,
                'name' => $this->deliveryMethod->name,
                'description' => $this->deliveryMethod->description ?? null,
                'base_price' => isset($this->deliveryMethod->base_price)
                    ? (float) $this->deliveryMethod->base_price
                    : null,
                'region' => $this->deliveryMethod->region ?? null,
            ] : null,

            'discount_id' => $this->discount_id,
            'discount' => $this->discount ? [
                'id' => $this->discount->id,
                'discount_code' => $this->discount->discount_code,
                'discount_description' => $this->discount->discount_description,
                'discount_percent' => (int) $this->discount->discount_percent,
            ] : null,

            'discount_amount' => (float) $this->discount_amount,
            'delivery_cost' => (float) $this->delivery_cost,
            'total_quantity' => (int) $this->total_quantity,
            'total_payment' => (float) $this->total_payment,

            'payment_method' => $this->payment_method,
            'payment_method_label' => $this->getPaymentMethodLabel(),

            'order_status' => $this->order_status,
            'order_status_label' => $this->getStatusLabel(),

            'items_count' => $this->whenCounted('items'),

            'address' => new OrderAddressResource($this->whenLoaded('orderAddress')),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'histories' => OrderHistoryResource::collection($this->whenLoaded('histories')),
            'payment' => new PaymentResource($this->whenLoaded('payment')),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }

    private function getStatusLabel(): string
    {
        return match ($this->order_status) {
            'pending' => 'Chờ xác nhận',
            'confirmed' => 'Đã xác nhận',
            'shipping' => 'Đang giao',
            'completed' => 'Hoàn thành',
            'cancelled' => 'Đã hủy',
            default => 'Không xác định',
        };
    }

    private function getPaymentMethodLabel(): string
    {
        return match ($this->payment_method) {
            'COD' => 'Thanh toán khi nhận hàng',
            'VNPAY' => 'VNPay',
            default => $this->payment_method,
        };
    }
}
