<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $package = $this->whenLoaded('package');
        $variant = $this->package?->variant;
        $product = $variant?->product;

        $primaryImage = $product?->images
            ?->sortByDesc('is_primary')
            ->sortBy('sort_order')
            ->first();

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'package_id' => $this->package_id,

            'quantity' => (int) $this->quantity,
            'price' => (float) $this->price,
            'subtotal' => (float) $this->price * (int) $this->quantity,

            'package' => $this->package ? [
                'id' => $this->package->id,
                'sku' => $this->package->sku,
                'size' => (float) $this->package->size,
                'unit' => $this->package->unit,
                'current_price' => (float) $this->package->price,
                'quantity_available' => (int) $this->package->quantity_available,
            ] : null,

            'variant' => $variant ? [
                'id' => $variant->id,
                'name' => $variant->variant_name,
            ] : null,

            'product' => $product ? [
                'id' => $product->id,
                'name' => $product->product_name,
                'primary_image' => $primaryImage
                    ? asset('storage/' . $primaryImage->image_url)
                    : null,
            ] : null,

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
