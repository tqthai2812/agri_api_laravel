<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderAddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,

            'receiver_name' => $this->receiver_name,
            'receiver_phone' => $this->receiver_phone,

            'province' => $this->province,
            'district' => $this->district,
            'ward' => $this->ward,

            'province_id' => $this->province_id,
            'district_id' => $this->district_id,
            'ward_id' => $this->ward_id,

            'address_detail' => $this->address_detail,

            'full_address' => collect([
                $this->address_detail,
                $this->ward,
                $this->district,
                $this->province,
            ])->filter()->implode(', '),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
