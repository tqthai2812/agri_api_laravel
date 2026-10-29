<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservationLot extends Model
{
    protected $fillable = ['stock_reservation_id', 'lot_id', 'quantity', 'discounted_quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'discounted_quantity' => 'integer'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(StockReservation::class, 'stock_reservation_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }
}
