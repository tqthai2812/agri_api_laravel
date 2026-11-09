<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUsageGuide extends Model
{
    use HasFactory;

    public const DOSE_UNIT_G = 'g';
    public const DOSE_UNIT_ML = 'ml';

    protected $table = 'product_usage_guides';

    protected $fillable = [
        'variant_id',
        'crop_id',
        'purpose',
        'growth_stage',
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

    protected $attributes = ['is_active' => false];

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

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class, 'crop_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}