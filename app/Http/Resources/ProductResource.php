<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_name' => $this->product_name,
            'description' => $this->description,
            'usage_instructions' => $this->usage_instructions,
            'safety_warning' => $this->safety_warning,
            'average_rating' => (float)$this->average_rating,
            'review_count' => $this->review_count,
            'is_show' => (bool)$this->is_show,
            'category' => [
                'id' => $this->category_id,
                'name' => $this->category->category_name ?? null,
            ],
            'origin' => [
                'id' => $this->origin_id,
                'name' => $this->origin->origin_name ?? null,
            ],
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
