<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductPurpose extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'description', 'aliases', 'is_active'];
    protected $attributes = ['is_active' => true];
    protected $casts = ['aliases' => 'array', 'is_active' => 'boolean'];

    public function usageGuides(): HasMany
    {
        return $this->hasMany(ProductUsageGuide::class, 'purpose_id');
    }
}