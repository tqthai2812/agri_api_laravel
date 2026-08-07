<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'name' => $this->category_name,
            'category_name' => $this->category_name,

            'description' => $this->category_description,
            'category_description' => $this->category_description,

            'slug' => $this->category_slug,
            'category_slug' => $this->category_slug,

            'subcategories_count' => $this->whenCounted('subcategories'),
            'products_count' => $this->whenCounted('products'),

            'subcategories' => SubcategoryResource::collection(
                $this->whenLoaded('subcategories')
            ),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
