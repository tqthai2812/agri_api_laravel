<?php

namespace App\Http\Requests\Client\Cart;

use Illuminate\Foundation\Http\FormRequest;

class StoreCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('quantity')) {
            $this->merge([
                'quantity' => (int) $this->quantity,
            ]);
        }

        if ($this->has('package_id')) {
            $this->merge([
                'package_id' => (int) $this->package_id,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'package_id' => [
                'required',
                'integer',
                'exists:product_packages,id',
            ],

            'quantity' => [
                'required',
                'integer',
                'min:1',
                'max:999',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'package_id.required' => 'Vui lòng chọn quy cách sản phẩm.',
            'package_id.exists' => 'Quy cách sản phẩm không tồn tại.',

            'quantity.required' => 'Vui lòng nhập số lượng.',
            'quantity.integer' => 'Số lượng phải là số nguyên.',
            'quantity.min' => 'Số lượng phải lớn hơn 0.',
            'quantity.max' => 'Số lượng không được vượt quá 999.',
        ];
    }
}
