<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'name' => $this->name,
            'description' => $this->description,

            'base_price' => (float) $this->base_price,
            'min_order_amount' => (float) $this->min_order_amount,

            'region' => $this->region,

            'is_active' => (bool) $this->is_active,
            'is_default' => (bool) $this->is_default,

            'orders_count' => $this->whenCounted('orders'),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
