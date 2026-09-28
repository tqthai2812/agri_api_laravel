<?php

namespace App\Models;

use App\Support\VietnameseText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

    protected $table = 'products';

    protected $fillable = [
        'category_id',
        'subcategory_id',
        'origin_id',
        'product_name',
        'brand',
        'description',
        'usage_instructions',
        'safety_warning',
        'average_rating',
        'review_count',
        'is_show',
        'search_text',
    ];

    protected $casts = [
        'average_rating' => 'decimal:2',
        'review_count' => 'integer',
        'is_show' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            $product->search_text = $product->makeSearchText();
        });
    }

    public function makeSearchText(): string
    {
        $categoryName = $this->category_id
            ? Category::query()
            ->whereKey($this->category_id)
            ->value('category_name')
            : '';

        $subcategoryName = $this->subcategory_id
            ? Subcategory::query()
            ->whereKey($this->subcategory_id)
            ->value('subcategory_name')
            : '';

        $originName = $this->origin_id
            ? Origin::query()
            ->whereKey($this->origin_id)
            ->value('origin_name')
            : '';

        return VietnameseText::normalize([
            $this->product_name,
            $this->description,
            $this->usage_instructions,
            $this->safety_warning,
            $categoryName,
            $subcategoryName,
            $originName,
        ]);
    }

    public function refreshSearchText(): bool
    {
        return $this->save();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class);
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Origin::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            Tag::class,
            'product_tags',
            'product_id',
            'tag_id'
        );
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(
            Supplier::class,
            'product_suppliers',
            'product_id',
            'supplier_id'
        )->withTimestamps();
    }

    public function productSuppliers(): HasMany
    {
        return $this->hasMany(
            ProductSupplier::class,
            'product_id'
        );
    }
}
