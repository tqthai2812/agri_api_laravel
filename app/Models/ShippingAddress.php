<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ShippingAddress extends Model
{
    /** @use HasFactory<\Database\Factories\ShippingAddressFactory> */
    use HasFactory;

    protected $table = 'shipping_addresses';

    protected $fillable = [
        'user_id',
        'receiver_name',
        'receiver_phone',
        'province',
        'district',
        'ward',
        'province_id',
        'district_id',
        'ward_id',
        'address_detail',
        'address_type',
        'is_default'
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    const TYPE_HOME = 'home';
    const TYPE_OFFICE = 'office';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
