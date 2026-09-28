<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLot extends Model
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_QUARANTINED = 'quarantined';
    public const STATUS_BLOCKED = 'blocked';

    protected $table = 'inventory_lots';

    protected $fillable = [
        'package_id',
        'receipt_item_id',
        'supplier_id',
        'lot_code',
        'manufactured_on',
        'expires_on',
        'received_at',
        'unit_cost',
        'quantity_on_hand',
        'status',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'manufactured_on' => 'date',
            'expires_on' => 'date',
            'received_at' => 'datetime',
            'unit_cost' => 'decimal:4',
            'quantity_on_hand' => 'integer',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(
            ProductPackage::class,
            'package_id'
        );
    }

    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(
            InventoryDocumentItem::class,
            'receipt_item_id'
        );
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(
            Supplier::class,
            'supplier_id'
        );
    }

    public function issueAllocations(): HasMany
    {
        return $this->hasMany(
            InventoryIssueAllocation::class,
            'lot_id'
        );
    }

    public function movements(): HasMany
    {
        return $this->hasMany(
            InventoryLotMovement::class,
            'lot_id'
        );
    }
}
