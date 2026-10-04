<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AdminContactResource extends ContactResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'admin_note' => $this->admin_note,
            'lock_version' => (int) $this->lock_version,
            'user' => $this->whenLoaded('user', fn() => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone_number' => $this->user->phone_number
            ] : null),
            'updater' => $this->whenLoaded('updater', fn() => $this->updater ? [
                'id' => $this->updater->id,
                'name' => $this->updater->name
            ] : null),
        ]);
    }
}
