<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicCategoryResource extends JsonResource
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

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
