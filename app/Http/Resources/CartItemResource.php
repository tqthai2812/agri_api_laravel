<?php

namespace App\Http\Resources;

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

        $primaryImage = $images
            ->sortBy(fn($image) => [
                $image->is_primary ? 0 : 1,
                (int) $image->sort_order,
            ])
            ->first();

        $imageItems = $images->map(fn($image) => [
            'id' => $image->id,
            'image_url' => $this->imageUrl($image->image_url),
            'is_primary' => (bool) $image->is_primary,
            'sort_order' => (int) $image->sort_order,
        ])->values();

        $productData = $product ? [
            'id' => $product->id,
            'name' => $product->product_name,
            'product_name' => $product->product_name,
            'primary_image' => $this->imageUrl($primaryImage?->image_url),
            'images' => $imageItems,
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

            'quantity' => (int) $this->quantity,

            'price' => $package ? (float) $package->price : 0,

            'subtotal' => $package
                ? (float) $package->price * (int) $this->quantity
                : 0,

            'package' => $package ? [
                'id' => $package->id,
                'sku' => $package->sku,
                'size' => (float) $package->size,
                'unit' => $package->unit,
                'price' => (float) $package->price,
                'quantity_available' => (int) $package->quantity_available,
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

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return asset('storage/' . ltrim($path, '/'));
    }
}
