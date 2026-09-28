<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InventoryDocumentItem extends Model
{
    protected $table = 'inventory_document_items';

    protected $fillable = [
        'document_id',
        'line_number',
        'package_id',
        'order_item_id',
        'quantity_change',
        'unit_cost',
        'product_name',
        'variant_name',
        'sku',
        'size',
        'unit',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity_change' => 'integer',
            'unit_cost' => 'decimal:2',
            'size' => 'decimal:2',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(
            InventoryDocument::class,
            'document_id'
        );
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(
            ProductPackage::class,
            'package_id'
        );
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(
            OrderItem::class,
            'order_item_id'
        );
    }

    public function inventoryTransaction(): HasOne
    {
        return $this->hasOne(
            InventoryTransaction::class,
            'document_item_id'
        );
    }

    public function receivedLot(): HasOne
    {
        return $this->hasOne(
            InventoryLot::class,
            'receipt_item_id'
        );
    }

    public function issueAllocations(): HasMany
    {
        return $this->hasMany(
            InventoryIssueAllocation::class,
            'document_item_id'
        );
    }
}
