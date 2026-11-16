<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductUsageGuide extends Model
{
    use HasFactory;

    public const DOSE_UNIT_G = 'g';
    public const DOSE_UNIT_ML = 'ml';

    protected $table = 'product_usage_guides';

    protected $fillable = [
        'variant_id',
        'crop_id',
        'purpose_id',
        'growth_stage_scope',
        'application_method',
        'dose_unit',
        'dose_per_ha_min',
        'dose_per_ha_max',
        'dose_per_liter_min',
        'dose_per_liter_max',
        'water_l_per_ha_min',
        'water_l_per_ha_max',
        'pre_harvest_days',
        'instructions',
        'safety_warning',
        'label_source',
        'is_active',
    ];

    protected $attributes = ['is_active' => false, 'growth_stage_scope' => 'unspecified'];

    // Decimal trả về chuỗi 6 chữ số thập phân; giá trị NULL vẫn là NULL.
    protected $casts = [
        'dose_per_ha_min' => 'decimal:6',
        'dose_per_ha_max' => 'decimal:6',
        'dose_per_liter_min' => 'decimal:6',
        'dose_per_liter_max' => 'decimal:6',
        'water_l_per_ha_min' => 'decimal:6',
        'water_l_per_ha_max' => 'decimal:6',
        'pre_harvest_days' => 'integer',
        'is_active' => 'boolean',
    ];

    public function purpose(): BelongsTo
    {
        return $this->belongsTo(ProductPurpose::class);
    }

    public function growthStages(): BelongsToMany
    {
        return $this->belongsToMany(CropGrowthStage::class, 'product_usage_guide_growth_stages', 'guide_id', 'growth_stage_id')
            ->using(ProductUsageGuideGrowthStage::class)->withPivot('crop_id')->orderBy('sort_order')->orderBy('crop_growth_stages.id');
    }

    public function knowledgeDocuments(): HasMany
    {
        return $this->hasMany(AiKnowledgeDocument::class, 'usage_guide_id');
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class, 'crop_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}