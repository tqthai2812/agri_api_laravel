<?php

namespace App\Http\Requests\Admin\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('quantity_change')) {
            $quantity = (int) $this->quantity_change;

            if ($this->transaction_type === 'export' && $quantity > 0) {
                $quantity *= -1;
            }

            if ($this->transaction_type === 'import' && $quantity < 0) {
                $quantity = abs($quantity);
            }

            $this->merge([
                'quantity_change' => $quantity,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'package_id' => [
                'required',
                'integer',
                Rule::exists('product_packages', 'id'),
            ],

            'quantity_change' => [
                'required',
                'integer',
                'not_in:0',
            ],

            'transaction_type' => [
                'required',
                Rule::in(['import', 'export', 'adjustment']),
            ],

            'note' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'package_id.required' => 'Vui lòng chọn quy cách sản phẩm.',
            'package_id.exists' => 'Quy cách sản phẩm không tồn tại.',

            'quantity_change.required' => 'Vui lòng nhập số lượng thay đổi.',
            'quantity_change.integer' => 'Số lượng thay đổi phải là số nguyên.',
            'quantity_change.not_in' => 'Số lượng thay đổi phải khác 0.',

            'transaction_type.required' => 'Vui lòng chọn loại giao dịch kho.',
            'transaction_type.in' => 'Loại giao dịch kho không hợp lệ.',

            'note.max' => 'Ghi chú không được vượt quá 1000 ký tự.',
        ];
    }
}
