<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryDocument extends Model
{
    public const TYPE_SUPPLIER_RECEIPT = 'supplier_receipt';
    public const TYPE_SALE_ISSUE = 'sale_issue';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_OPENING_BALANCE = 'opening_balance';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_POSTED = 'posted';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'inventory_documents';

    protected $fillable = [
        'document_number',
        'event_key',
        'document_type',
        'status',
        'supplier_id',
        'supplier_name',
        'order_id',
        'document_date',
        'note',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(
            Supplier::class,
            'supplier_id'
        );
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(
            Order::class,
            'order_id'
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'posted_by'
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(
            InventoryDocumentItem::class,
            'document_id'
        );
    }
}
