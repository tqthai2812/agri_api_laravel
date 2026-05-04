<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Category extends Model
{
    /** @use HasFactory<\Database\Factories\CategoryFactory> */
    use HasFactory;

    protected $table = 'categories';

    protected $fillable = ['category_name', 'category_description', 'category_slug'];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($category) {
            if (empty($category->category_slug)) {
                $slug = Str::slug($category->category_name);

                $count = self::where('category_slug', 'like', "$slug%")->count();

                $category->category_slug = $count ? "$slug-$count" : $slug;
            }
        });
    }

    public function subcategories(): HasMany
    {
        return $this->hasMany(Subcategory::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
