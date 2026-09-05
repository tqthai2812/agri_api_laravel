<?php

namespace App\Http\Requests\Client\Checkout;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
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

            'shipping_address_id' => ['nullable', 'integer', 'exists:shipping_addresses,id'],

            'delivery_id' => ['required', 'integer', 'exists:delivery_methods,id'],
            'discount_code' => ['nullable', 'string', 'max:50'],

            'payment_method' => [
                'required',
                Rule::in([
                    Order::PAYMENT_COD,
                    Order::PAYMENT_VNPAY,
                ]),
            ],

            'note' => ['nullable', 'string', 'max:1000'],

            'receiver_name' => ['required_without:shipping_address_id', 'string', 'max:255'],
            'receiver_phone' => ['required_without:shipping_address_id', 'string', 'max:20'],
            'province' => ['required_without:shipping_address_id', 'string', 'max:255'],
            'district' => ['required_without:shipping_address_id', 'string', 'max:255'],
            'ward' => ['required_without:shipping_address_id', 'string', 'max:255'],

            'province_id' => ['nullable', 'string', 'max:255'],
            'district_id' => ['nullable', 'string', 'max:255'],
            'ward_id' => ['nullable', 'string', 'max:255'],

            'address_detail' => ['required_without:shipping_address_id', 'string', 'max:255'],
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

            'payment_method.required' => 'Vui lòng chọn phương thức thanh toán.',
            'payment_method.in' => 'Phương thức thanh toán không hợp lệ.',

            'receiver_name.required_without' => 'Vui lòng nhập tên người nhận.',
            'receiver_phone.required_without' => 'Vui lòng nhập số điện thoại người nhận.',
            'province.required_without' => 'Vui lòng nhập tỉnh/thành.',
            'district.required_without' => 'Vui lòng nhập quận/huyện.',
            'ward.required_without' => 'Vui lòng nhập phường/xã.',
            'address_detail.required_without' => 'Vui lòng nhập địa chỉ chi tiết.',
        ];
    }
}
