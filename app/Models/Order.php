<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    protected $table = 'orders';

    protected $fillable = [
        'user_id',
        'note',
        'delivery_id',
        'discount_amount',
        'discount_id',
        'delivery_cost',
        'total_quantity',
        'total_payment',
        'payment_method',
        'order_status',
        'completed_at',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'delivery_cost' => 'decimal:2',
        'total_quantity' => 'integer',
        'total_payment' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    const PAYMENT_COD = 'COD';
    const PAYMENT_VNPAY = 'VNPAY';

    const STATUS_PENDING = 'pending';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_SHIPPING = 'shipping';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveryMethod(): BelongsTo
    {
        return $this->belongsTo(
            DeliveryMethod::class,
            'delivery_id'
        );
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(
            Discount::class,
            'discount_id'
        );
    }

    public function orderAddress(): HasOne
    {
        return $this->hasOne(
            OrderAddress::class,
            'order_id'
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(
            OrderItem::class,
            'order_id'
        );
    }

    public function histories(): HasMany
    {
        return $this->hasMany(
            OrderHistory::class,
            'order_id'
        );
    }

    /**
     * Giữ để tương thích với code cũ.
     *
     * Không dùng quan hệ này để kết luận đơn đã thanh toán:
     * một đơn có thể có nhiều bản ghi payments.
     */
    public function payment(): HasOne
    {
        return $this->hasOne(
            Payment::class,
            'order_id'
        );
    }

    /**
     * Toàn bộ các lần thử thanh toán của đơn.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(
            Payment::class,
            'order_id'
        );
    }

    public function inventoryDocuments(): HasMany
    {
        return $this->hasMany(
            InventoryDocument::class,
            'order_id'
        );
    }

    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(
            InventoryTransaction::class,
            'order_id'
        );
    }
}
