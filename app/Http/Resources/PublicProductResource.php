<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class PublicProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $images = $this->relationLoaded('images')
            ? $this->images
            : collect();

        $variants = $this->relationLoaded('variants')
            ? $this->variants
            : collect();

        $primaryImage = $images->firstWhere('is_primary', true)
            ?? $images->sortBy('sort_order')->first();

        $packages = $variants->flatMap(fn($variant) => $variant->packages ?? collect());

        $minPrice = $this->resource->getAttribute('min_price');
        $maxPrice = $this->resource->getAttribute('max_price');
        $totalStock = $this->resource->getAttribute('total_stock');
        $firstPackageId = $this->resource->getAttribute('first_package_id');
        $primaryImagePath = $this->resource->getAttribute('primary_image_path');

        if ($minPrice === null && $packages->isNotEmpty()) {
            $minPrice = $packages->min('price');
        }

        if ($maxPrice === null && $packages->isNotEmpty()) {
            $maxPrice = $packages->max('price');
        }

        if ($totalStock === null && $packages->isNotEmpty()) {
            $totalStock = $packages->sum('quantity_available');
        }

        if ($firstPackageId === null && $packages->isNotEmpty()) {
            $firstPackageId = $packages
                ->sortBy(function ($package) {
                    $outOfStockScore = (int) ((int) $package->quantity_available <= 0) * 1000000000;

                    return $outOfStockScore + (float) $package->price;
                })
                ->first()?->id;
        }

        return [
            'id' => $this->id,

            'name' => $this->product_name,
            'product_name' => $this->product_name,

            'description' => $this->description,

            'usage_instructions' => $this->when(
                $this->resource->getAttribute('usage_instructions') !== null,
                $this->usage_instructions
            ),

            'safety_warning' => $this->when(
                $this->resource->getAttribute('safety_warning') !== null,
                $this->safety_warning
            ),

            'average_rating' => (float) ($this->average_rating ?? 0),
            'review_count' => (int) ($this->review_count ?? 0),
            'is_show' => (bool) $this->is_show,

            'category' => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->category_name,
                'category_name' => $this->category->category_name,
                'slug' => $this->category->category_slug,
                'category_slug' => $this->category->category_slug,
            ] : null,

            'subcategory' => $this->subcategory ? [
                'id' => $this->subcategory->id,
                'name' => $this->subcategory->subcategory_name,
                'subcategory_name' => $this->subcategory->subcategory_name,
                'slug' => $this->subcategory->subcategory_slug,
                'subcategory_slug' => $this->subcategory->subcategory_slug,
            ] : null,

            'origin' => $this->origin ? [
                'id' => $this->origin->id,
                'name' => $this->origin->origin_name,
                'origin_name' => $this->origin->origin_name,
                'image' => $this->imageUrl($this->origin->origin_image),
                'origin_image' => $this->imageUrl($this->origin->origin_image),
            ] : null,

            'primary_image' => $this->imageUrl(
                $primaryImagePath ?: $primaryImage?->image_url
            ),

            'images' => $this->relationLoaded('images')
                ? $images->map(fn($image) => [
                    'id' => $image->id,
                    'image_url' => $this->imageUrl($image->image_url),
                    'is_primary' => (bool) $image->is_primary,
                    'sort_order' => (int) $image->sort_order,
                ])->values()
                : [],

            'variants' => $this->relationLoaded('variants')
                ? $variants->map(fn($variant) => [
                    'id' => $variant->id,
                    'variant_name' => $variant->variant_name,
                    'name' => $variant->variant_name,

                    'packages' => $variant->packages?->map(fn($package) => [
                        'id' => $package->id,
                        'sku' => $package->sku,
                        'size' => (float) $package->size,
                        'unit' => $package->unit,
                        'price' => (float) $package->price,
                        'quantity_available' => (int) $package->quantity_available,
                        'barcode' => $package->barcode,
                        'box_barcode' => $package->box_barcode,
                    ])->values() ?? [],
                ])->values()
                : [],

            'min_price' => (float) ($minPrice ?: 0),
            'max_price' => (float) ($maxPrice ?: 0),
            'total_stock' => (int) ($totalStock ?: 0),
            'first_package_id' => $firstPackageId ? (int) $firstPackageId : null,

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
