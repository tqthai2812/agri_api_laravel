<?php

namespace App\Http\Requests\Admin\InventoryDocument;

class UpdateInventoryDocumentRequest extends InventoryDocumentWriteRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'event_key' => ['prohibited'],
        ]);
    }
}
