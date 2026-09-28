<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_code' => $this->supplier_code,
            'name' => $this->name,
            'contact_name' => $this->contact_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'tax_code' => $this->tax_code,
            'note' => $this->note,
            'is_active' => (bool) $this->is_active,

            'products_count' => $this->whenCounted('products'),

            'products' => $this->whenLoaded(
                'products',
                fn() => $this->products->map(fn($product) => [
                    'id' => $product->id,
                    'product_name' => $product->product_name,
                ])->values()
            ),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
