<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class OrderAddress extends Model
{
    /** @use HasFactory<\Database\Factories\OrderAddressFactory> */
    use HasFactory;

    protected $table = 'order_addresses';

    protected $fillable = [
        'order_id',
        'receiver_name',
        'receiver_phone',
        'province',
        'district',
        'ward',
        'province_id',
        'district_id',
        'ward_id',
        'address_detail'
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
