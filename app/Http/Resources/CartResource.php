<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $items = $this->items ?? collect();

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,

            'items' => CartItemResource::collection($items),

            'total_quantity' => $items->sum('quantity'),
            'subtotal' => $items->sum(function ($item) {
                return (float) $item->package?->price * (int) $item->quantity;
            }),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
