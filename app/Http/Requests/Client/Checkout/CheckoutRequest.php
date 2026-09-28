<?php

namespace App\Http\Requests\Client\Checkout;

use Illuminate\Validation\Rule;

class CheckoutRequest extends CheckoutPreviewRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'payment_method' => [
                'required',
                Rule::in(['COD']),
            ],

            'note' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'payment_method.required' =>
            'Vui lòng chọn phương thức thanh toán.',

            'payment_method.in' =>
            'Hiện chỉ hỗ trợ COD. Thanh toán trực tuyến chưa được kích hoạt.',
        ]);
    }
}
