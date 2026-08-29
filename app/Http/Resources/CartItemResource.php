<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $package = $this->package;
        $variant = $package?->variant;
        $product = $variant?->product;

        $primaryImage = $product?->images
            ?->sortByDesc('is_primary')
            ->sortBy('sort_order')
            ->first();

        return [
            'id' => $this->id,
            'cart_id' => $this->cart_id,
            'package_id' => $this->package_id,

            'quantity' => (int) $this->quantity,
            'price' => $package ? (float) $package->price : 0,
            'subtotal' => $package ? (float) $package->price * (int) $this->quantity : 0,

            'package' => $package ? [
                'id' => $package->id,
                'sku' => $package->sku,
                'size' => (float) $package->size,
                'unit' => $package->unit,
                'price' => (float) $package->price,
                'quantity_available' => (int) $package->quantity_available,
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
