<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class InventoryTransaction extends Model
{
    protected $fillable = [
        'package_id',
        'order_id',
        'document_item_id',
        'quantity_change',
        'transaction_type',
        'quantity_before',
        'quantity_after',
        'occurred_at',
        'performed_by',
        'note',
    ];

    protected $casts = [
        'quantity_change' => 'integer',
        'quantity_before' => 'integer',
        'quantity_after' => 'integer',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        $reject = static function () {
            throw ValidationException::withMessages([
                'transaction' => [
                    'Nhật ký kho đã ghi không được sửa hoặc xóa. Hãy lập phiếu điều chỉnh.',
                ],
            ]);
        };

        static::updating($reject);
        static::deleting($reject);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(ProductPackage::class, 'package_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function documentItem(): BelongsTo
    {
        return $this->belongsTo(
            InventoryDocumentItem::class,
            'document_item_id'
        );
    }
}
