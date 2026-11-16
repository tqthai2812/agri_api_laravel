<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CropGrowthStage extends Model
{
    use HasFactory;

    protected $fillable = ['crop_id', 'name', 'slug', 'description', 'aliases', 'sort_order', 'is_active'];
    protected $attributes = ['sort_order' => 0, 'is_active' => true];
    protected $casts = ['aliases' => 'array', 'sort_order' => 'integer', 'is_active' => 'boolean'];

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }

    public function usageGuides(): BelongsToMany
    {
        return $this->belongsToMany(ProductUsageGuide::class, 'product_usage_guide_growth_stages', 'growth_stage_id', 'guide_id')
            ->using(ProductUsageGuideGrowthStage::class)->withPivot('crop_id');
    }
}