<?php

namespace App\Http\Requests\Client\Cart;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'package_id' => [
                'bail',
                'required',
                'integer',
                'min:1',
                Rule::exists('product_packages', 'id'),
            ],

            'quantity' => [
                'bail',
                'required',
                'integer',
                'min:1',
                'max:999',
            ],

            // Giỏ và người sở hữu được xác định từ tài khoản đăng nhập.
            'cart_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'package_id.required' => 'Vui lòng chọn quy cách sản phẩm.',
            'package_id.integer' => 'Mã quy cách sản phẩm phải là số nguyên.',
            'package_id.min' => 'Mã quy cách sản phẩm không hợp lệ.',
            'package_id.exists' => 'Quy cách sản phẩm không tồn tại.',

            'quantity.required' => 'Vui lòng nhập số lượng.',
            'quantity.integer' => 'Số lượng phải là số nguyên.',
            'quantity.min' => 'Số lượng phải lớn hơn 0.',
            'quantity.max' => 'Số lượng mỗi lần thêm không được vượt quá 999.',

            'cart_id.prohibited' => 'Không được tự chỉ định giỏ hàng.',
            'user_id.prohibited' => 'Không được tự chỉ định chủ sở hữu giỏ hàng.',
        ];
    }
}
