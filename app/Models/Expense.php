<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Expense extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_POSTED = 'posted';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'expenses';

    protected $fillable = [
        'entry_key',
        'category_id',
        'order_id',
        'payment_id',
        'inventory_document_id',
        'reverses_expense_id',
        'amount',
        'incurred_at',
        'paid_at',
        'status',
        'created_by',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'incurred_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(
            ExpenseCategory::class,
            'category_id'
        );
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(
            Order::class,
            'order_id'
        );
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(
            Payment::class,
            'payment_id'
        );
    }

    public function inventoryDocument(): BelongsTo
    {
        return $this->belongsTo(
            InventoryDocument::class,
            'inventory_document_id'
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    /**
     * Khoản chi gốc mà dòng hiện tại đang đảo.
     */
    public function originalExpense(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'reverses_expense_id'
        );
    }

    /**
     * Dòng đảo của khoản chi hiện tại.
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(
            self::class,
            'reverses_expense_id'
        );
    }
}
