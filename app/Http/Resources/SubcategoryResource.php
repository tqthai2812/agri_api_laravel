<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubcategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'name' => $this->subcategory_name,
            'subcategory_name' => $this->subcategory_name,

            'slug' => $this->subcategory_slug,
            'subcategory_slug' => $this->subcategory_slug,

            'category_id' => $this->category_id,
            'category_name' => $this->category?->category_name,

            'category' => new CategoryResource($this->whenLoaded('category')),

            'products_count' => $this->whenCounted('products'),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
