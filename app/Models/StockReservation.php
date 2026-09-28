<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservation extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_RELEASED = 'released';

    protected $table = 'stock_reservations';

    protected $fillable = [
        'order_item_id',
        'package_id',
        'quantity',
        'status',
        'expires_at',
        'consumed_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(
            OrderItem::class,
            'order_item_id'
        );
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(
            ProductPackage::class,
            'package_id'
        );
    }
}
