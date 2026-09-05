<?php

namespace App\Http\Requests\Client\ShippingAddress;

use App\Models\ShippingAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateShippingAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_default')) {
            $this->merge([
                'is_default' => filter_var(
                    $this->is_default,
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                ),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'receiver_name' => ['sometimes', 'required', 'string', 'max:255'],
            'receiver_phone' => ['sometimes', 'required', 'string', 'max:20'],

            'province' => ['sometimes', 'required', 'string', 'max:255'],
            'district' => ['sometimes', 'required', 'string', 'max:255'],
            'ward' => ['sometimes', 'required', 'string', 'max:255'],

            'province_id' => ['nullable', 'string', 'max:255'],
            'district_id' => ['nullable', 'string', 'max:255'],
            'ward_id' => ['nullable', 'string', 'max:255'],

            'address_detail' => ['sometimes', 'required', 'string', 'max:255'],

            'address_type' => [
                'nullable',
                Rule::in([
                    ShippingAddress::TYPE_HOME,
                    ShippingAddress::TYPE_OFFICE,
                ]),
            ],

            'is_default' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'receiver_name.required' => 'Vui lòng nhập tên người nhận.',
            'receiver_phone.required' => 'Vui lòng nhập số điện thoại người nhận.',
            'province.required' => 'Vui lòng nhập tỉnh/thành.',
            'district.required' => 'Vui lòng nhập quận/huyện.',
            'ward.required' => 'Vui lòng nhập phường/xã.',
            'address_detail.required' => 'Vui lòng nhập địa chỉ chi tiết.',
            'address_type.in' => 'Loại địa chỉ không hợp lệ.',
        ];
    }
}
