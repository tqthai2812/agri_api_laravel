<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryLotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $expiresOn = $this->expires_on?->toDateString();

        $eligible = $this->status === 'available'
            && (
                $expiresOn === null
                || $expiresOn >= now()->toDateString()
            );

        return [
            'id' => $this->id,
            'package_id' => $this->package_id,
            'receipt_item_id' => $this->receipt_item_id,
            'supplier_id' => $this->supplier_id,
            'lot_code' => $this->lot_code,

            'manufactured_on' => $this->manufactured_on?->toDateString(),
            'expires_on' => $expiresOn,
            'received_at' => $this->received_at?->toDateTimeString(),

            'unit_cost' => $this->unit_cost,
            'quantity_on_hand' => (int) $this->quantity_on_hand,
            'status' => $this->status,
            'note' => $this->note,

            // Đây là tồn lô hợp lệ trước khi trừ giữ hàng cấp SKU.
            'eligible_quantity' => $eligible
                ? (int) $this->quantity_on_hand
                : 0,

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
