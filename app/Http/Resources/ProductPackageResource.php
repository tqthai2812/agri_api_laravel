<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductPackageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'size' => (float)$this->size,
            'unit' => $this->unit,
            'price' => (float)$this->price,
            'quantity_available' => $this->quantity_available,
            'barcode' => $this->barcode,
            'box_barcode' => $this->box_barcode,
        ];
    }
}
