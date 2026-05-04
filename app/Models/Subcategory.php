<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Subcategory extends Model
{
    /** @use HasFactory<\Database\Factories\SubcategoryFactory> */
    use HasFactory;

    protected $table = 'subcategories';

    protected $fillable = ['category_id', 'subcategory_name', 'subcategory_slug'];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($subcategory) {
            if (empty($subcategory->subcategory_slug)) {
                $subcategory->subcategory_slug = Str::slug($subcategory->subcategory_name);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
