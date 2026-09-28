<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $table = 'suppliers';

    protected $fillable = [
        'supplier_code',
        'name',
        'contact_name',
        'phone',
        'email',
        'address',
        'tax_code',
        'note',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'product_suppliers',
            'supplier_id',
            'product_id'
        )->withTimestamps();
    }

    public function productSuppliers(): HasMany
    {
        return $this->hasMany(
            ProductSupplier::class,
            'supplier_id'
        );
    }

    public function inventoryDocuments(): HasMany
    {
        return $this->hasMany(
            InventoryDocument::class,
            'supplier_id'
        );
    }

    public function inventoryLots(): HasMany
    {
        return $this->hasMany(
            InventoryLot::class,
            'supplier_id'
        );
    }
}
