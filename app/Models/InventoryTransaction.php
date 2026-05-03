<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class InventoryTransaction extends Model
{
    /** @use HasFactory<\Database\Factories\InventoryTransactionFactory> */
    use HasFactory;

    protected $table = 'inventory_transactions';

    protected $fillable = [
        'package_id',
        'quantity_change',
        'transaction_type',
        'note',
        'performed_by'
    ];

    protected $casts = [
        'quantity_change' => 'integer',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(ProductPackage::class, 'package_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
