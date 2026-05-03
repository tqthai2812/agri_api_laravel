<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class ProductPackage extends Model
{
    /** @use HasFactory<\Database\Factories\ProductPackageFactory> */
    use HasFactory;

    protected $table = 'product_packages';

    protected $fillable = [
        'variant_id',
        'sku',
        'size',
        'unit',
        'price',
        'quantity_available',
        'barcode',
        'box_barcode'
    ];

    protected $casts = [
        'size' => 'decimal:2',
        'price' => 'decimal:2',
        'quantity_available' => 'integer',
    ];

    const UNIT_KG = 'kg';
    const UNIT_G = 'g';
    const UNIT_ML = 'ml';
    const UNIT_L = 'l';
    const UNIT_PIECE = 'piece';

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }
}
