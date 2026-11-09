<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Crop extends Model
{
    use HasFactory;

    protected $table = 'crops';

    protected $fillable = ['name', 'slug', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected $casts = ['is_active' => 'boolean'];

    public function usageGuides(): HasMany
    {
        return $this->hasMany(ProductUsageGuide::class, 'crop_id');
    }
}