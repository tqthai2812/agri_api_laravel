<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItem extends Model
{
    /** @use HasFactory<\Database\Factories\OrderItemFactory> */
    use HasFactory;

    protected $table = 'order_items';

    protected $fillable = [
        'order_id',
        'package_id',
        'quantity',
        'price',
        'product_name',
        'variant_name',
        'sku',
        'size',
        'unit',
        'discount_amount',
        'net_sales_amount',
        'cost_total',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:2',
        'size' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'net_sales_amount' => 'decimal:2',
        'cost_total' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(
            Order::class,
            'order_id'
        );
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(
            ProductPackage::class,
            'package_id'
        );
    }

    public function inventoryDocumentItems(): HasMany
    {
        return $this->hasMany(
            InventoryDocumentItem::class,
            'order_item_id'
        );
    }

    /**
     * order_item_id là unique trong stock_reservations.
     */
    public function stockReservation(): HasOne
    {
        return $this->hasOne(
            StockReservation::class,
            'order_item_id'
        );
    }

    public function inventoryIssueAllocations(): HasMany
    {
        return $this->hasMany(
            InventoryIssueAllocation::class,
            'order_item_id'
        );
    }

    /**
     * order_item_id là unique trong product_reviews.
     */
    public function productReview(): HasOne
    {
        return $this->hasOne(
            ProductReview::class,
            'order_item_id'
        );
    }
}
