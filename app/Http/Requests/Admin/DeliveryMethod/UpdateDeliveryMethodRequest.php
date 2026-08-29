<?php

namespace App\Http\Requests\Admin\DeliveryMethod;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeliveryMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['is_active', 'is_default'] as $field) {
            if ($this->has($field)) {
                $this->merge([
                    $field => filter_var($this->input($field), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                ]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'base_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'region' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên phương thức giao hàng.',
            'name.max' => 'Tên phương thức giao hàng không được vượt quá 255 ký tự.',

            'base_price.required' => 'Vui lòng nhập phí giao hàng.',
            'base_price.numeric' => 'Phí giao hàng phải là số.',
            'base_price.min' => 'Phí giao hàng không được nhỏ hơn 0.',

            'min_order_amount.numeric' => 'Giá trị đơn hàng tối thiểu phải là số.',
            'min_order_amount.min' => 'Giá trị đơn hàng tối thiểu không được nhỏ hơn 0.',

            'region.max' => 'Khu vực không được vượt quá 255 ký tự.',
        ];
    }
}
