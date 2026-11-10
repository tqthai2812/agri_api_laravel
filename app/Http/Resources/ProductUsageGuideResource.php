<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductUsageGuideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hasDose = $this->dose_per_ha_min !== null || $this->dose_per_liter_min !== null;
        $hasSource = trim((string) $this->label_source) !== '';

        return [
            'id' => $this->id,
            'variant_id' => $this->variant_id,
            'crop_id' => $this->crop_id,
            'purpose' => $this->purpose,
            'growth_stage' => $this->growth_stage,
            'application_method' => $this->application_method,
            'dose_unit' => $this->dose_unit,
            // Giữ chuỗi decimal:6 từ model, giữ nguyên NULL.
            'dose_per_ha_min' => $this->dose_per_ha_min,
            'dose_per_ha_max' => $this->dose_per_ha_max,
            'dose_per_liter_min' => $this->dose_per_liter_min,
            'dose_per_liter_max' => $this->dose_per_liter_max,
            'water_l_per_ha_min' => $this->water_l_per_ha_min,
            'water_l_per_ha_max' => $this->water_l_per_ha_max,
            'pre_harvest_days' => $this->pre_harvest_days,
            'instructions' => $this->instructions,
            'safety_warning' => $this->safety_warning,
            'label_source' => $this->label_source,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'crop' => $this->whenLoaded('crop', fn () => [
                'id' => $this->crop->id, 'name' => $this->crop->name,
                'slug' => $this->crop->slug, 'is_active' => (bool) $this->crop->is_active,
            ]),
            'variant' => $this->whenLoaded('variant', fn () => [
                'id' => $this->variant->id,
                'variant_name' => $this->variant->variant_name,
                'product_id' => $this->variant->product_id,
                'product' => $this->variant->relationLoaded('product') && $this->variant->product
                    ? [
                        'id' => $this->variant->product->id,
                        'product_name' => $this->variant->product->product_name,
                        'is_show' => (bool) $this->variant->product->is_show,
                    ] : null,
            ]),
            'can_calculate' => (bool) $this->is_active && $hasDose && $hasSource
                && in_array($this->dose_unit, ['g', 'ml'], true),
        ];
    }
}