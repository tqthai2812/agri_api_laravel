<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CropGrowthStageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'crop_id' => $this->crop_id, 'name' => $this->name,
            'slug' => $this->slug, 'description' => $this->description, 'aliases' => $this->aliases,
            'sort_order' => $this->sort_order, 'is_active' => (bool) $this->is_active,
            'crop' => $this->whenLoaded('crop', fn () => new CropResource($this->crop)),
            'usage_guides_count' => $this->whenCounted('usageGuides'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}