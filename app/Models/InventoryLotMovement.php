<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLotMovement extends Model
{
    protected $table = 'inventory_lot_movements';

    protected $fillable = [
        'inventory_transaction_id',
        'lot_id',
        'quantity_change',
        'unit_cost',
        'value_change',
        'quantity_before',
        'quantity_after',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_change' => 'integer',
            'unit_cost' => 'decimal:4',
            'value_change' => 'decimal:2',
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function inventoryTransaction(): BelongsTo
    {
        return $this->belongsTo(
            InventoryTransaction::class,
            'inventory_transaction_id'
        );
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(
            InventoryLot::class,
            'lot_id'
        );
    }
}
