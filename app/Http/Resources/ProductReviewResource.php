<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $item = $this->relationLoaded('orderItem') ? $this->orderItem : null;
        $order = $item?->relationLoaded('order') ? $item->order : null;
        $package = $item?->relationLoaded('package') ? $item->package : null;
        $variant = $package?->relationLoaded('variant') ? $package->variant : null;
        $verified = $this->parent_id === null && $order
            && $order->order_status === 'completed'
            && (int) $order->user_id === (int) $this->user_id
            && (int) $variant?->product_id === (int) $this->product_id;

        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'rating' => $this->rating,
            'content' => $this->content,
            'status' => $this->status,
            'user' => $this->whenLoaded('user', fn() => [
                'name' => $this->user?->name ?? 'Khách hàng',
            ]),
            'is_verified_purchase' => (bool) $verified,
            'purchase' => $verified ? [
                'variant_name' => $item->variant_name,
                'size' => $item->size,
                'unit' => $item->unit,
            ] : null,
            'replies' => self::collection($this->whenLoaded('replies')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
