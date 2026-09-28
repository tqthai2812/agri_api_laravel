<?php

namespace App\Http\Resources;

use App\Support\OrderMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $items = $this->relationLoaded('items')
            ? $this->items
            : collect();

        $subtotal = 0;

        foreach ($items as $item) {
            if ($item->package && (int) $item->quantity > 0) {
                $subtotal = OrderMoney::add(
                    $subtotal,
                    OrderMoney::multiply(
                        OrderMoney::cents($item->package->price),
                        (int) $item->quantity
                    )
                );
            }
        }

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'items' => CartItemResource::collection($items),
            'total_items' => $items->count(),
            'total_quantity' => (int) $items->sum('quantity'),
            'subtotal' => (float) OrderMoney::decimal($subtotal),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
