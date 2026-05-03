<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    /** @use HasFactory<\Database\Factories\ProductVariantFactory> */
    use HasFactory;

    protected $table = 'product_variants';

    protected $fillable = ['product_id', 'variant_name'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(ProductPackage::class);
    }
}
