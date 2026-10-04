<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductReview extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_HIDDEN = 'hidden';

    protected $table = 'product_reviews';

    protected $fillable = [
        'user_id',
        'product_id',
        'order_item_id',
        'parent_id',
        'content',
        'rating',
        'status',
        'is_shop_reply',
    ];

    protected $casts = ['rating' => 'integer', 'is_shop_reply' => 'boolean'];

    public function scopePublishedRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id')
            ->where('status', self::STATUS_PUBLISHED)
            ->whereBetween('rating', [1, 5]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
