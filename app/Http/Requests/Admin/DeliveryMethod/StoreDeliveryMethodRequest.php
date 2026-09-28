<?php

namespace App\Http\Requests\Admin\DeliveryMethod;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeliveryMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Giữ cơ chế phân quyền tại middleware/controller hiện tại.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        foreach (['name', 'description', 'region'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $value = trim($value);

                $data[$field] = $field === 'name'
                    ? $value
                    : ($value === '' ? null : $value);
            }
        }

        foreach (['is_active', 'is_default'] as $field) {
            if (!$this->has($field)) {
                continue;
            }

            $value = $this->input($field);

            // Giữ hỗ trợ chuỗi true/false.
            // Giá trị không hợp lệ được giữ lại để validation từ chối.
            if (is_string($value)) {
                $normalized = filter_var(
                    $value,
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                );

                if ($normalized !== null) {
                    $data[$field] = $normalized;
                }
            }
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],

            'base_price' => ['required', 'numeric', 'min:0'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],

            'region' => [
                'nullable',
                'string',
                'max:255',
                Rule::in(array_keys(config('shipping.regions', []))),
            ],

            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' =>
            'Vui lòng nhập tên phương thức giao hàng.',
            'name.max' =>
            'Tên phương thức giao hàng không được vượt quá 255 ký tự.',

            'description.max' =>
            'Mô tả không được vượt quá 1000 ký tự.',

            'base_price.required' =>
            'Vui lòng nhập phí giao hàng.',
            'base_price.numeric' =>
            'Phí giao hàng phải là số.',
            'base_price.min' =>
            'Phí giao hàng không được nhỏ hơn 0.',

            'min_order_amount.numeric' =>
            'Giá trị đơn hàng tối thiểu phải là số.',
            'min_order_amount.min' =>
            'Giá trị đơn hàng tối thiểu không được nhỏ hơn 0.',

            'region.max' =>
            'Khu vực không được vượt quá 255 ký tự.',
            'region.in' =>
            'Khu vực giao hàng không hợp lệ. Vui lòng chọn lại trong danh sách.',

            'is_active.boolean' =>
            'Trạng thái kích hoạt không hợp lệ.',
            'is_default.boolean' =>
            'Trạng thái mặc định không hợp lệ.',
        ];
    }
}
