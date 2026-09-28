<?php

namespace App\Http\Resources;

use App\Support\OrderMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $package = $this->package;
        $variant = $package?->variant;
        $product = $variant?->product;

        $images = $product?->images ?? collect();
        $image = $images->firstWhere('is_primary', true)
            ?? $images->sortBy('sort_order')->first();

        $productName = $this->product_name ?? $product?->product_name;
        $variantName = $this->variant_name ?? $variant?->variant_name;
        $sku = $this->sku ?? $package?->sku;
        $size = $this->size ?? $package?->size;
        $unit = $this->unit ?? $package?->unit;

        $productData = [
            'id' => $product?->id,
            'name' => $productName,
            'product_name' => $productName,
            'primary_image' => $this->imageUrl($image?->image_url),
        ];

        $variantData = [
            'id' => $variant?->id,
            'name' => $variantName,
            'variant_name' => $variantName,
            'product' => $productData,
        ];

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'package_id' => $this->package_id,
            'quantity' => (int) $this->quantity,
            'price' => (float) $this->price,
            'subtotal' => (float) OrderMoney::decimal(
                OrderMoney::multiply(
                    OrderMoney::cents($this->price),
                    (int) $this->quantity
                )
            ),

            'product_name' => $productName,
            'variant_name' => $variantName,
            'sku' => $sku,
            'size' => $size === null ? null : (float) $size,
            'unit' => $unit,

            'discount_amount' => $this->discount_amount === null
                ? null : (float) $this->discount_amount,

            'net_sales_amount' => $this->net_sales_amount === null
                ? null : (float) $this->net_sales_amount,

            // Không công khai giá vốn cho khách hàng thông thường.
            'cost_total' => $this->when(
                $request->user()?->can('order.view') ?? false,
                fn() => $this->cost_total
            ),

            'package' => [
                'id' => $this->package_id,
                'sku' => $sku,
                'size' => $size === null ? null : (float) $size,
                'unit' => $unit,
                'price' => (float) $this->price,
                'variant' => $variantData,
            ],
            'variant' => $variantData,
            'product' => $productData,
        ];
    }

    private function imageUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        return Str::startsWith($path, ['http://', 'https://'])
            ? $path
            : asset('storage/' . ltrim($path, '/'));
    }
}
