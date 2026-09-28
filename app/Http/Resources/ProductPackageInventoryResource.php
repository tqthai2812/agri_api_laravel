<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ProductPackageInventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $package = $this->resource;

        $variant = $package->relationLoaded('variant')
            ? $package->getRelation('variant')
            : null;

        $product = $variant && $variant->relationLoaded('product')
            ? $variant->getRelation('product')
            : null;

        $category = $product && $product->relationLoaded('category')
            ? $product->getRelation('category')
            : null;

        $subcategory = $product && $product->relationLoaded('subcategory')
            ? $product->getRelation('subcategory')
            : null;

        $origin = $product && $product->relationLoaded('origin')
            ? $product->getRelation('origin')
            : null;

        $images = $product && $product->relationLoaded('images')
            ? $product->getRelation('images')
            : collect();

        $primaryImage = $images->firstWhere('is_primary', true)
            ?? $images->sortBy('sort_order')->first();

        $stock = (int) $package->quantity_available;
        $reorderLevel = (int) $package->reorder_level;

        $availableToSell = $this->integerAttribute('available_to_sell');
        $lotQuantity = $this->integerAttribute('lot_quantity');
        $lotCount = $this->integerAttribute('lot_count');
        $reservedQuantity = $this->integerAttribute('reserved_quantity');

        $stockStatus = $this->getStockStatus($stock, $reorderLevel);

        return [
            'id' => $package->id,
            'variant_id' => $package->variant_id,
            'sku' => $package->sku,
            'size' => (float) $package->size,
            'unit' => $package->unit,
            'price' => (float) $package->price,

            // Tồn vật lý, giữ ý nghĩa của API quản lý kho cũ.
            'quantity_available' => $stock,
            'reorder_level' => $reorderLevel,

            // Số liệu tính từ các bảng kho.
            // NULL nghĩa là query chưa cung cấp số liệu này.
            'available_to_sell' => $availableToSell,
            'lot_quantity' => $lotQuantity,
            'lot_count' => $lotCount,
            'reserved_quantity' => $reservedQuantity,

            'stock_consistent' => $lotQuantity === null
                ? null
                : $lotQuantity === $stock,

            'barcode' => $package->barcode,
            'box_barcode' => $package->box_barcode,

            'stock_status' => $stockStatus,
            'stock_status_label' => $this->getStockStatusLabel($stockStatus),

            'variant' => [
                'id' => $variant?->id,
                'name' => $variant?->variant_name,
            ],

            'product' => [
                'id' => $product?->id,
                'name' => $product?->product_name,
                'is_show' => (bool) $product?->is_show,
                'primary_image' => $this->imageUrl(
                    $primaryImage?->image_url
                ),
            ],

            'category' => [
                'id' => $category?->id,
                'name' => $category?->category_name,
            ],

            'subcategory' => [
                'id' => $subcategory?->id,
                'name' => $subcategory?->subcategory_name,
            ],

            'origin' => [
                'id' => $origin?->id,
                'name' => $origin?->origin_name,
            ],

            'transactions' => InventoryTransactionResource::collection(
                $this->whenLoaded('inventoryTransactions')
            ),

            'created_at' => $package->created_at?->toDateTimeString(),
            'updated_at' => $package->updated_at?->toDateTimeString(),
        ];
    }

    private function integerAttribute(string $name): ?int
    {
        $attributes = $this->resource->getAttributes();

        if (
            !array_key_exists($name, $attributes)
            || $attributes[$name] === null
        ) {
            return null;
        }

        return (int) $attributes[$name];
    }

    private function getStockStatus(int $stock, int $reorderLevel): string
    {
        if ($stock <= 0) {
            return 'out';
        }

        if ($stock <= $reorderLevel) {
            return 'low';
        }

        return 'available';
    }

    private function getStockStatusLabel(string $status): string
    {
        return match ($status) {
            'out' => 'Hết hàng',
            'low' => 'Sắp hết',
            default => 'Còn hàng',
        };
    }

    private function imageUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return asset('storage/' . ltrim($path, '/'));
    }
}
