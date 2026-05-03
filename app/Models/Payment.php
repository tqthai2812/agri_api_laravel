<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentFactory> */
    use HasFactory;

    protected $table = 'payments';

    protected $fillable = [
        'order_id',
        'payment_method',
        'transaction_id',
        'amount',
        'status',
        'paid_at',
        'failed_reason'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_PAID = 'paid';
    const STATUS_FAILED = 'failed';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
