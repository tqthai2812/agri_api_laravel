<?php

namespace App\Http\Resources;

use App\Support\OrderMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class CartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $package = $this->package;
        $variant = $package?->variant;
        $product = $variant?->product;
        $images = $product?->images ?? collect();

        $primary = $images->sortBy(fn($image) => [
            $image->is_primary ? 0 : 1,
            (int) $image->sort_order,
            (int) $image->id,
        ])->first();

        $available = $package?->getAttribute('available_to_sell');
        $available = $available === null ? null : (int) $available;

        $quantity = (int) $this->quantity;

        $subtotal = $package && $quantity > 0
            ? OrderMoney::multiply(
                OrderMoney::cents($package->price),
                $quantity
            )
            : 0;

        $productData = $product ? [
            'id' => $product->id,
            'name' => $product->product_name,
            'product_name' => $product->product_name,
            'is_show' => (bool) $product->is_show,
            'primary_image' => $this->imageUrl($primary?->image_url),
            'images' => $images->map(fn($image) => [
                'id' => $image->id,
                'image_url' => $this->imageUrl($image->image_url),
                'is_primary' => (bool) $image->is_primary,
                'sort_order' => (int) $image->sort_order,
            ])->values(),
        ] : null;

        $variantData = $variant ? [
            'id' => $variant->id,
            'name' => $variant->variant_name,
            'variant_name' => $variant->variant_name,
            'product' => $productData,
        ] : null;

        return [
            'id' => $this->id,
            'cart_id' => $this->cart_id,
            'package_id' => $this->package_id,
            'quantity' => $quantity,
            'price' => $package ? (float) $package->price : 0,
            'subtotal' => (float) OrderMoney::decimal($subtotal),

            'can_checkout' => $product
                && (bool) $product->is_show
                && $available !== null
                && $quantity > 0
                && $quantity <= $available,

            'package' => $package ? [
                'id' => $package->id,
                'sku' => $package->sku,
                'size' => (float) $package->size,
                'unit' => $package->unit,
                'price' => (float) $package->price,
                'quantity_available' => (int) $package->quantity_available,
                'available_to_sell' => $available,
                'variant' => $variantData,
            ] : null,

            'variant' => $variantData,
            'product' => $productData,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }

    private function imageUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        return Str::startsWith($path, ['http://', 'https://'])
            ? $path
            : asset('storage/' . ltrim($path, '/'));
    }
}
