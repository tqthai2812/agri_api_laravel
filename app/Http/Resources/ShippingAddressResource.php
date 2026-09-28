<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingAddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $addressParts = collect([
            $this->address_detail,
            $this->ward,
            $this->district,
            $this->province,
        ])
            ->map(fn($value) => trim((string) ($value ?? '')))
            ->filter(fn($value) => $value !== '');

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,

            'receiver_name' => $this->receiver_name,
            'receiver_phone' => $this->receiver_phone,

            'province' => $this->province,
            'district' => $this->district,
            'ward' => $this->ward,

            'province_id' => $this->province_id,
            'district_id' => $this->district_id,
            'ward_id' => $this->ward_id,

            'address_detail' => $this->address_detail,
            'address_type' => $this->address_type,
            'is_default' => (bool) $this->is_default,

            'full_address' => $addressParts->implode(', '),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
