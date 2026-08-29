<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $usageLimit = $this->usage_limit;
        $usedCount = (int) $this->used_count;

        return [
            'id' => $this->id,

            'user_id' => $this->user_id,

            'user' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null,

            'discount_code' => $this->discount_code,
            'discount_description' => $this->discount_description,
            'discount_percent' => (int) $this->discount_percent,

            'max_discount_amount' => (float) $this->max_discount_amount,
            'min_order_value' => (float) $this->min_order_value,

            'usage_limit' => $usageLimit,
            'used_count' => $usedCount,
            'remaining_usage' => $usageLimit === null
                ? null
                : max(0, (int) $usageLimit - $usedCount),

            'expire_date' => $this->expire_date?->format('Y-m-d'),

            'is_active' => (bool) $this->is_active,
            'is_expired' => $this->isExpired(),
            'is_used_up' => $this->isUsedUp(),

            'status' => $this->getStatus(),
            'status_label' => $this->getStatusLabel(),

            'orders_count' => $this->whenCounted('orders'),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }

    private function isExpired(): bool
    {
        return $this->expire_date
            ? $this->expire_date->lt(now()->startOfDay())
            : false;
    }

    private function isUsedUp(): bool
    {
        return $this->usage_limit !== null
            && (int) $this->used_count >= (int) $this->usage_limit;
    }

    private function getStatus(): string
    {
        if (!$this->is_active) {
            return 'inactive';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        if ($this->isUsedUp()) {
            return 'used_up';
        }

        return 'available';
    }

    private function getStatusLabel(): string
    {
        return match ($this->getStatus()) {
            'inactive' => 'Đã tắt',
            'expired' => 'Hết hạn',
            'used_up' => 'Hết lượt',
            'available' => 'Có hiệu lực',
            default => 'Không xác định',
        };
    }
}
