<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ProductResource extends JsonResource
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
            'product_name' => $this->product_name,
            'name' => $this->product_name,
            'brand' => $this->brand,

            'description' => $this->description,
            'usage_instructions' => $this->usage_instructions,
            'safety_warning' => $this->safety_warning,

            'average_rating' => (float) $this->average_rating,
            'review_count' => (int) $this->review_count,
            'is_show' => (bool) $this->is_show,
            'status' => $this->is_show ? 'Aktif' : 'Non-aktif',

            'category_id' => $this->category_id,
            'subcategory_id' => $this->subcategory_id,
            'origin_id' => $this->origin_id,

            'category' => [
                'id' => $this->category_id,
                'name' => $category?->category_name,
            ],
            'subcategory' => [
                'id' => $this->subcategory_id,
                'name' => $subcategory?->subcategory_name,
            ],
            'origin' => [
                'id' => $this->origin_id,
                'name' => $origin?->origin_name,
                'image' => $this->imageUrl($origin?->origin_image),
            ],

            'primary_image' => $this->imageUrl($primaryImage?->image_url),
            'images' => ProductImageResource::collection(
                $this->whenLoaded('images')
            ),

            'variants' => $variants->map(function ($variant) use ($request) {
                // Giữ các field mà Resource cũ đang trả.
                $variantData = (new ProductVariantResource($variant))
                    ->resolve($request);

                $variantData['id'] = $variant->id;

                if ($variant->relationLoaded('packages')) {
                    // Chuyển phần nested Resource thành mảng JSON thực tế.
                    $serialized = json_decode(
                        json_encode($variantData, JSON_THROW_ON_ERROR),
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );

                    $packageMap = $variant->packages->keyBy('id');

                    $serialized['packages'] = array_map(
                        function (array $packageData) use ($packageMap) {
                            $package = $packageMap->get($packageData['id'] ?? null);

                            if ($package) {
                                $packageData['reorder_level'] =
                                    (int) $package->reorder_level;
                            }

                            return $packageData;
                        },
                        $serialized['packages'] ?? []
                    );

                    return $serialized;
                }

                return $variantData;
            })->values(),

            'variant_count' => $variants->count(),
            'package_count' => $packages->count(),
            'min_price' => $packages->isNotEmpty()
                ? (float) $packages->min('price')
                : 0,
            'max_price' => $packages->isNotEmpty()
                ? (float) $packages->max('price')
                : 0,

            'total_stock' => (int) $packages->sum('quantity_available'),
            'first_sku' => $packages->first()?->sku,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
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
