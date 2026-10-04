<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => 'LH' . str_pad((string) $this->id, 6, '0', STR_PAD_LEFT),
            'subject' => $this->subject,
            'message' => $this->message,
            'status' => $this->status,
            'status_label' => match ($this->status) {
                'pending' => 'Chờ xử lý',
                'resolved' => 'Đã xử lý',
                'rejected' => 'Từ chối',
                default => 'Không xác định',
            },
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String()
        ];
    }
}
