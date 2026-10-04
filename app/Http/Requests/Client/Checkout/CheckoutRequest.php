<?php

namespace App\Http\Requests\Client\Checkout;

use Illuminate\Validation\Rule;

class CheckoutRequest extends CheckoutPreviewRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'payment_method' => ['required', Rule::in(['COD', 'VNPAY'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
    }
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'payment_method.required' => 'Vui lòng chọn phương thức thanh toán.',
            'payment_method.in' => 'Phương thức thanh toán không hợp lệ.',
        ]);
    }
}
