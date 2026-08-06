<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $packages = $this->whenLoaded('variants', function () {
            return $this->variants
                ->flatMap(fn($variant) => $variant->packages ?? collect());
        }, collect());

        $primaryImage = $this->whenLoaded('images', function () {
            return $this->images->firstWhere('is_primary', true)
                ?? $this->images->sortBy('sort_order')->first();
        });

        return [
            'id' => $this->id,

            'product_name' => $this->product_name,
            'name' => $this->product_name,

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
                'name' => $this->category?->category_name,
            ],

            'subcategory' => [
                'id' => $this->subcategory_id,
                'name' => $this->subcategory?->subcategory_name,
            ],

            'origin' => [
                'id' => $this->origin_id,
                'name' => $this->origin?->origin_name,
                'image' => $this->origin?->origin_image
                    ? asset('storage/' . $this->origin->origin_image)
                    : null,
            ],

            'primary_image' => $primaryImage
                ? asset('storage/' . $primaryImage->image_url)
                : null,

            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),

            'variant_count' => $this->whenLoaded('variants', fn() => $this->variants->count(), 0),
            'package_count' => $packages->count(),

            'min_price' => $packages->count() ? (float) $packages->min('price') : 0,
            'max_price' => $packages->count() ? (float) $packages->max('price') : 0,
            'total_stock' => $packages->sum('quantity_available'),

            'first_sku' => $packages->first()?->sku,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
