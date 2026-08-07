<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $package = $this->package;
        $variant = $package?->variant;
        $product = $variant?->product;

        return [
            'id' => $this->id,
            'package_id' => $this->package_id,
            'quantity_change' => (int) $this->quantity_change,
            'transaction_type' => $this->transaction_type,
            'transaction_type_label' => $this->getTransactionTypeLabel(),
            'note' => $this->note,

            'package' => [
                'id' => $package?->id,
                'sku' => $package?->sku,
                'size' => $package ? (float) $package->size : null,
                'unit' => $package?->unit,
                'price' => $package ? (float) $package->price : null,
                'quantity_available' => $package?->quantity_available,
            ],

            'variant' => [
                'id' => $variant?->id,
                'name' => $variant?->variant_name,
            ],

            'product' => [
                'id' => $product?->id,
                'name' => $product?->product_name,
            ],

            'performer' => [
                'id' => $this->performer?->id,
                'name' => $this->performer?->name,
                'email' => $this->performer?->email,
            ],

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }

    private function getTransactionTypeLabel(): string
    {
        return match ($this->transaction_type) {
            'import' => 'Nhập kho',
            'export' => 'Xuất kho',
            'adjustment' => 'Điều chỉnh',
            default => 'Không xác định',
        };
    }
}
