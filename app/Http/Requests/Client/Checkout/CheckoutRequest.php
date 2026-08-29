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
    }

    public function rules(): array
    {
        return [
            'delivery_id' => ['required', 'integer', 'exists:delivery_methods,id'],
            'discount_code' => ['nullable', 'string', 'max:50'],
            'payment_method' => ['required', Rule::in([Order::PAYMENT_COD, Order::PAYMENT_VNPAY])],
            'note' => ['nullable', 'string', 'max:1000'],

            'receiver_name' => ['required', 'string', 'max:255'],
            'receiver_phone' => ['required', 'string', 'max:20'],
            'province' => ['required', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:255'],
            'ward' => ['required', 'string', 'max:255'],
            'province_id' => ['nullable', 'string', 'max:255'],
            'district_id' => ['nullable', 'string', 'max:255'],
            'ward_id' => ['nullable', 'string', 'max:255'],
            'address_detail' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'delivery_id.required' => 'Vui lòng chọn phương thức giao hàng.',
            'payment_method.required' => 'Vui lòng chọn phương thức thanh toán.',
            'receiver_name.required' => 'Vui lòng nhập tên người nhận.',
            'receiver_phone.required' => 'Vui lòng nhập số điện thoại người nhận.',
            'province.required' => 'Vui lòng nhập tỉnh/thành.',
            'district.required' => 'Vui lòng nhập quận/huyện.',
            'ward.required' => 'Vui lòng nhập phường/xã.',
            'address_detail.required' => 'Vui lòng nhập địa chỉ chi tiết.',
        ];
    }
}
