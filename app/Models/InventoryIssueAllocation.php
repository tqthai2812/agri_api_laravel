<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryIssueAllocation extends Model
{
    protected $table = 'inventory_issue_allocations';

    protected $fillable = [
        'document_item_id',
        'lot_id',
        'order_item_id',
        'quantity',
        'unit_cost',
        'cost_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'decimal:4',
            'cost_amount' => 'decimal:2',
        ];
    }

    public function documentItem(): BelongsTo
    {
        return $this->belongsTo(
            InventoryDocumentItem::class,
            'document_item_id'
        );
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(
            InventoryLot::class,
            'lot_id'
        );
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(
            OrderItem::class,
            'order_item_id'
        );
    }
}
