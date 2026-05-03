<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    /** @use HasFactory<\Database\Factories\DiscountFactory> */
    use HasFactory;

    protected $table = 'discounts';

    protected $fillable = [
        'user_id',
        'discount_description',
        'discount_percent',
        'max_discount_amount',
        'min_order_value',
        'usage_limit',
        'used_count',
        'expire_date',
        'is_active'
    ];

    protected $casts = [
        'discount_percent' => 'integer',
        'max_discount_amount' => 'decimal:2',
        'min_order_value' => 'decimal:2',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
        'expire_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'discount_id');
    }
}
