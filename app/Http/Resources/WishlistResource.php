<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class WishlistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->whenLoaded('product');
        $images = $product?->images ?? collect();
        $variants = $product?->variants ?? collect();

        $primaryImage = $images
            ->sortBy(fn($image) => [
                $image->is_primary ? 0 : 1,
                (int) $image->sort_order,
            ])
            ->first();

        $packages = $variants
            ->flatMap(fn($variant) => $variant->packages ?? collect());

        $availablePackages = $packages->filter(function ($package) {
            return (int) $package->quantity_available > 0;
        });

        $prices = $availablePackages
            ->pluck('price')
            ->map(fn($price) => (float) $price)
            ->filter(fn($price) => $price > 0)
            ->values();

        $minPrice = $prices->count() ? $prices->min() : 0;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'product_id' => $this->product_id,

            'product' => $product ? [
                'id' => $product->id,

                'product_name' => $product->product_name,
                'name' => $product->product_name,

                'description' => $product->description,
                'usage_instructions' => $product->usage_instructions,
                'safety_warning' => $product->safety_warning,

                'brand' => $product->origin?->origin_name,
                'origin' => $product->origin ? [
                    'id' => $product->origin->id,
                    'name' => $product->origin->origin_name,
                    'origin_name' => $product->origin->origin_name,
                    'origin_image' => $this->imageUrl($product->origin->origin_image),
                ] : null,

                'category' => $product->category ? [
                    'id' => $product->category->id,
                    'name' => $product->category->category_name,
                    'category_name' => $product->category->category_name,
                    'slug' => $product->category->category_slug,
                ] : null,

                'subcategory' => $product->subcategory ? [
                    'id' => $product->subcategory->id,
                    'name' => $product->subcategory->subcategory_name,
                    'subcategory_name' => $product->subcategory->subcategory_name,
                    'slug' => $product->subcategory->subcategory_slug,
                ] : null,

                'primary_image' => $this->imageUrl($primaryImage?->image_url),

                'images' => $images->map(fn($image) => [
                    'id' => $image->id,
                    'image_url' => $this->imageUrl($image->image_url),
                    'is_primary' => (bool) $image->is_primary,
                    'sort_order' => (int) $image->sort_order,
                ])->values(),

                'variants' => $variants->map(fn($variant) => [
                    'id' => $variant->id,
                    'variant_name' => $variant->variant_name,
                    'name' => $variant->variant_name,

                    'packages' => ($variant->packages ?? collect())->map(fn($package) => [
                        'id' => $package->id,
                        'sku' => $package->sku,
                        'size' => (float) $package->size,
                        'unit' => $package->unit,
                        'price' => (float) $package->price,
                        'quantity_available' => (int) $package->quantity_available,
                        'barcode' => $package->barcode,
                        'box_barcode' => $package->box_barcode,
                    ])->values(),
                ])->values(),

                'min_price' => (float) $minPrice,
                'total_stock' => (int) $packages->sum('quantity_available'),

                'rating' => (float) $product->average_rating,
                'average_rating' => (float) $product->average_rating,
                'review_count' => (int) $product->review_count,

                'is_show' => (bool) $product->is_show,
                'created_at' => $product->created_at?->toDateTimeString(),
                'updated_at' => $product->updated_at?->toDateTimeString(),
            ] : null,

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
