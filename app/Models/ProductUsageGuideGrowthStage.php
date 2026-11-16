<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ProductUsageGuideGrowthStage extends Pivot
{
    protected $table = 'product_usage_guide_growth_stages';
    protected $fillable = ['guide_id', 'growth_stage_id', 'crop_id'];
    public $timestamps = false;
    public $incrementing = false;
}