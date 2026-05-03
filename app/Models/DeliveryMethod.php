<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryMethod extends Model
{
    /** @use HasFactory<\Database\Factories\DeliveryMethodFactory> */
    use HasFactory;

    protected $table = 'delivery_methods';

    protected $fillable = [
        'name',
        'description',
        'base_price',
        'min_order_amount',
        'region',
        'is_active',
        'is_default'
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'delivery_id');
    }
}
