<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class ProductReview extends Model
{
    /** @use HasFactory<\Database\Factories\ProductReviewFactory> */
    use HasFactory;

    protected $table = 'product_reviews';

    protected $fillable = ['user_id', 'product_id', 'parent_id', 'content', 'rating'];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProductReview::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ProductReview::class, 'parent_id');
    }
}
