<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class PublicProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $images = $this->resource->relationLoaded('images')
            ? $this->images
            : collect();

        $variants = $this->resource->relationLoaded('variants')
            ? $this->variants
            : collect();

        $packages = $variants->flatMap(function ($variant) {
            return $variant->relationLoaded('packages')
                ? $variant->packages
                : collect();
        });

        $primaryImage = $images->firstWhere('is_primary', true)
            ?? $images->sortBy('sort_order')->first();

        $minPrice = $this->resource->getAttribute('min_price');
        $maxPrice = $this->resource->getAttribute('max_price');
        $totalStock = $this->resource->getAttribute('total_stock');
        $availableStock = $this->resource->getAttribute('available_stock');
        $firstPackageId = $this->resource->getAttribute('first_package_id');
        $primaryImagePath = $this->resource->getAttribute('primary_image_path');

        $minPrice ??= $packages->min('price');
        $maxPrice ??= $packages->max('price');
        $totalStock ??= $packages->sum('quantity_available');
        $availableStock ??= $packages->sum(
            fn($package) => (int) ($package->available_to_sell ?? 0)
        );

        if ($firstPackageId === null && $packages->isNotEmpty()) {
            $firstPackageId = $packages->sort(function ($a, $b) {
                $aUnavailable = (int) (($a->available_to_sell ?? 0) <= 0);
                $bUnavailable = (int) (($b->available_to_sell ?? 0) <= 0);

                return ($aUnavailable <=> $bUnavailable)
                    ?: ((float) $a->price <=> (float) $b->price)
                    ?: ($a->id <=> $b->id);
            })->first()?->id;
        }

        $category = $this->resource->relationLoaded('category')
            ? $this->category
            : null;

        $subcategory = $this->resource->relationLoaded('subcategory')
            ? $this->subcategory
            : null;

        $origin = $this->resource->relationLoaded('origin')
            ? $this->origin
            : null;

        return [
            'id' => $this->id,
            'name' => $this->product_name,
            'product_name' => $this->product_name,
            'brand' => $this->brand,
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

            'category' => $category ? [
                'id' => $category->id,
                'name' => $category->category_name,
                'category_name' => $category->category_name,
                'slug' => $category->category_slug,
                'category_slug' => $category->category_slug,
            ] : null,

            'subcategory' => $subcategory ? [
                'id' => $subcategory->id,
                'name' => $subcategory->subcategory_name,
                'subcategory_name' => $subcategory->subcategory_name,
                'slug' => $subcategory->subcategory_slug,
                'subcategory_slug' => $subcategory->subcategory_slug,
            ] : null,

            'origin' => $origin ? [
                'id' => $origin->id,
                'name' => $origin->origin_name,
                'origin_name' => $origin->origin_name,
                'image' => $this->imageUrl($origin->origin_image),
                'origin_image' => $this->imageUrl($origin->origin_image),
            ] : null,

            'primary_image' => $this->imageUrl(
                $primaryImagePath ?: $primaryImage?->image_url
            ),

            'images' => $images->map(fn($image) => [
                'id' => $image->id,
                'image_url' => $this->imageUrl($image->image_url),
                'is_primary' => (bool) $image->is_primary,
                'sort_order' => (int) $image->sort_order,
            ])->values(),

            'variants' => $variants->map(function ($variant) {
                $variantPackages = $variant->relationLoaded('packages')
                    ? $variant->packages
                    : collect();

                return [
                    'id' => $variant->id,
                    'variant_name' => $variant->variant_name,
                    'name' => $variant->variant_name,
                    'packages' => $variantPackages->map(fn($package) => [
                        'id' => $package->id,
                        'sku' => $package->sku,
                        'size' => (float) $package->size,
                        'unit' => $package->unit,
                        'price' => (float) $package->price,
                        'quantity_available' => (int) $package->quantity_available,
                        'available_to_sell' => (int) ($package->available_to_sell ?? 0),
                        'barcode' => $package->barcode,
                        'box_barcode' => $package->box_barcode,
                    ])->values(),
                ];
            })->values(),

            'min_price' => (float) ($minPrice ?? 0),
            'max_price' => (float) ($maxPrice ?? 0),
            'total_stock' => (int) ($totalStock ?? 0),
            'available_stock' => (int) ($availableStock ?? 0),
            'first_package_id' => $firstPackageId
                ? (int) $firstPackageId
                : null,

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
