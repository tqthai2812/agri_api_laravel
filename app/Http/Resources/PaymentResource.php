<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,

            'payment_method' => $this->payment_method,
            'transaction_id' => $this->transaction_id,

            'amount' => (float) $this->amount,

            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),

            'paid_at' => $this->paid_at?->toDateTimeString(),
            'failed_reason' => $this->failed_reason,

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }

    private function getStatusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Chờ thanh toán',
            'paid' => 'Đã thanh toán',
            'failed' => 'Thanh toán thất bại',
            default => 'Không xác định',
        };
    }
}
