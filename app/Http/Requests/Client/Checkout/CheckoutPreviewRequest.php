<?php

namespace App\Http\Requests\Client\Checkout;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('discount_code')) {
            $this->merge([
                'discount_code' => strtoupper(trim((string) $this->discount_code)),
            ]);
        }

        if ($this->has('cart_item_ids') && is_string($this->cart_item_ids)) {
            $this->merge([
                'cart_item_ids' => array_values(array_filter(
                    array_map('intval', explode(',', $this->cart_item_ids))
                )),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'cart_item_ids' => ['required', 'array', 'min:1'],
            'cart_item_ids.*' => ['required', 'integer', 'exists:cart_items,id'],

            'delivery_id' => ['required', 'integer', 'exists:delivery_methods,id'],
            'discount_code' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'cart_item_ids.required' => 'Vui lòng chọn sản phẩm cần thanh toán.',
            'cart_item_ids.array' => 'Danh sách sản phẩm thanh toán không hợp lệ.',
            'cart_item_ids.min' => 'Vui lòng chọn ít nhất một sản phẩm để thanh toán.',
            'cart_item_ids.*.exists' => 'Một sản phẩm trong giỏ hàng không tồn tại.',

            'delivery_id.required' => 'Vui lòng chọn phương thức giao hàng.',
            'delivery_id.exists' => 'Phương thức giao hàng không tồn tại.',
        ];
    }
}
