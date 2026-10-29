<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use HasFactory;

    protected $table = 'discounts';

    protected $fillable = [
        'discount_scope',
        'lot_id',
        'starts_at',
        'show_in_wallet',
        'user_id',
        'discount_code',
        'discount_description',
        'discount_percent',
        'max_discount_amount',
        'min_order_value',
        'usage_limit',
        'used_count',
        'expire_date',
        'is_active',
    ];

    protected $casts = [
        'lot_id' => 'integer',
        'starts_at' => 'datetime',
        'show_in_wallet' => 'boolean',
        'discount_percent' => 'integer',
        'max_discount_amount' => 'decimal:2',
        'min_order_value' => 'decimal:2',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
        'expire_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'discount_id');
    }
}
