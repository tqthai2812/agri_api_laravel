<?php

namespace App\Http\Requests\Client\Cart;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'quantity' => [
                'bail',
                'required',
                'integer',
                'min:1',
                'max:999',
            ],

            'package_id' => ['prohibited'],
            'cart_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.required' => 'Vui lòng nhập số lượng.',
            'quantity.integer' => 'Số lượng phải là số nguyên.',
            'quantity.min' => 'Số lượng phải lớn hơn 0.',
            'quantity.max' => 'Số lượng cập nhật không được vượt quá 999.',

            'package_id.prohibited' => 'Không được đổi quy cách của dòng giỏ hàng.',
            'cart_id.prohibited' => 'Không được chuyển dòng hàng sang giỏ khác.',
            'user_id.prohibited' => 'Không được tự chỉ định chủ sở hữu giỏ hàng.',
        ];
    }
}
