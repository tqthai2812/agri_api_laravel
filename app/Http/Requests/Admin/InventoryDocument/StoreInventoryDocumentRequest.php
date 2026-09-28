<?php

namespace App\Http\Requests\Admin\InventoryDocument;

class StoreInventoryDocumentRequest extends InventoryDocumentWriteRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'event_key' => ['required', 'uuid'],
        ]);
    }
}
