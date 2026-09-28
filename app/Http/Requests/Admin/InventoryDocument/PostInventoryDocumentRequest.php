<?php

namespace App\Http\Requests\Admin\InventoryDocument;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PostInventoryDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lots = $this->input('lots');

        if (!is_array($lots)) {
            return;
        }

        foreach ($lots as $index => $lot) {
            if (
                is_array($lot)
                && isset($lot['lot_code'])
                && is_string($lot['lot_code'])
            ) {
                $lots[$index]['lot_code'] = trim($lot['lot_code']);
            }
        }

        $this->merge(['lots' => $lots]);
    }

    public function rules(): array
    {
        return [
            'lots' => ['required', 'array', 'list', 'min:1', 'max:100'],

            'lots.*' => [
                'required',
                'array:document_item_id,lot_code,manufactured_on,expires_on,status,note',
            ],

            'lots.*.document_item_id' => [
                'required',
                'integer',
                'min:1',
                'distinct',
            ],

            // Không unique: schema cho phép mã lô lặp ở các lần nhập.
            'lots.*.lot_code' => ['required', 'string', 'max:100'],

            'lots.*.manufactured_on' => [
                'nullable',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],

            'lots.*.expires_on' => [
                'nullable',
                'date_format:Y-m-d',
            ],

            'lots.*.status' => [
                'required',
                Rule::in(['available', 'quarantined', 'blocked']),
            ],

            'lots.*.note' => ['nullable', 'string', 'max:10000'],

            'quantity_available' => ['prohibited'],
            'posted_by' => ['prohibited'],
            'posted_at' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach ($this->input('lots', []) as $index => $lot) {
                    $manufacturedOn = $lot['manufactured_on'] ?? null;
                    $expiresOn = $lot['expires_on'] ?? null;

                    if (
                        $manufacturedOn
                        && $expiresOn
                        && $expiresOn < $manufacturedOn
                    ) {
                        $validator->errors()->add(
                            "lots.{$index}.expires_on",
                            'Hạn dùng phải bằng hoặc sau ngày sản xuất.'
                        );
                    }
                }
            },
        ];
    }
}
