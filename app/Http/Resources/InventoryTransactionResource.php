<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $package = $this->resource->relationLoaded('package')
            ? $this->package
            : null;

        $variant = $package?->relationLoaded('variant')
            ? $package->variant
            : null;

        $product = $variant?->relationLoaded('product')
            ? $variant->product
            : null;

        $performer = $this->resource->relationLoaded('performer')
            ? $this->performer
            : null;

        $item = $this->resource->relationLoaded('documentItem')
            ? $this->documentItem
            : null;

        return [
            'id' => $this->id,
            'package_id' => $this->package_id,
            'order_id' => $this->order_id,
            'document_item_id' => $this->document_item_id,
            'document_id' => $item?->document_id,

            'quantity_change' => (int) $this->quantity_change,

            'quantity_before' => $this->quantity_before === null
                ? null
                : (int) $this->quantity_before,

            'quantity_after' => $this->quantity_after === null
                ? null
                : (int) $this->quantity_after,

            'transaction_type' => $this->transaction_type,
            'transaction_type_label' => match ($this->transaction_type) {
                'import', 'supplier_receipt' => 'Nhập kho',
                'export', 'sale_issue' => 'Xuất kho',
                'adjustment' => 'Điều chỉnh',
                'opening_balance' => 'Tồn đầu kỳ',
                default => $this->transaction_type,
            },

            'note' => $this->note,
            'can_edit' => false,

            'snapshot' => $item ? [
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                'size' => $item->size,
                'unit' => $item->unit,
                'unit_cost' => $item->unit_cost,
            ] : null,

            // Đây là thông tin hiện tại, không phải giá/tồn tại lúc giao dịch.
            'package' => [
                'id' => $package?->id,
                'sku' => $package?->sku,
                'size' => $package ? (float) $package->size : null,
                'unit' => $package?->unit,
                'price' => $package ? (float) $package->price : null,
                'quantity_available' => $package
                    ? (int) $package->quantity_available
                    : null,
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
                'id' => $performer?->id,
                'name' => $performer?->name,
                'email' => $performer?->email,
            ],

            'occurred_at' => $this->occurred_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
