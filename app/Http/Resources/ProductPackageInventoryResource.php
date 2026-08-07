<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductPackageInventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variant = $this->variant;
        $product = $variant?->product;

        $primaryImage = $product?->images
            ? ($product->images->firstWhere('is_primary', true) ?? $product->images->sortBy('sort_order')->first())
            : null;

        $stock = (int) $this->quantity_available;

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'size' => (float) $this->size,
            'unit' => $this->unit,
            'price' => (float) $this->price,
            'quantity_available' => $stock,
            'barcode' => $this->barcode,
            'box_barcode' => $this->box_barcode,

            'stock_status' => $this->getStockStatus($stock),
            'stock_status_label' => $this->getStockStatusLabel($stock),

            'variant' => [
                'id' => $variant?->id,
                'name' => $variant?->variant_name,
            ],

            'product' => [
                'id' => $product?->id,
                'name' => $product?->product_name,
                'is_show' => (bool) $product?->is_show,
                'primary_image' => $primaryImage
                    ? asset('storage/' . $primaryImage->image_url)
                    : null,
            ],

            'category' => [
                'id' => $product?->category?->id,
                'name' => $product?->category?->category_name,
            ],

            'subcategory' => [
                'id' => $product?->subcategory?->id,
                'name' => $product?->subcategory?->subcategory_name,
            ],

            'origin' => [
                'id' => $product?->origin?->id,
                'name' => $product?->origin?->origin_name,
            ],

            'transactions' => InventoryTransactionResource::collection(
                $this->whenLoaded('inventoryTransactions')
            ),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }

    private function getStockStatus(int $stock): string
    {
        if ($stock <= 0) {
            return 'out';
        }

        if ($stock <= 5) {
            return 'low';
        }

        return 'available';
    }

    private function getStockStatusLabel(int $stock): string
    {
        if ($stock <= 0) {
            return 'Hết hàng';
        }

        if ($stock <= 5) {
            return 'Sắp hết';
        }

        return 'Còn hàng';
    }
}
