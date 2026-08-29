<?php

namespace App\Http\Requests\Admin\Discount;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class StoreDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_active')) {
            $this->merge([
                'is_active' => filter_var($this->is_active, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }

        if ($this->has('discount_code')) {
            $this->merge([
                'discount_code' => Str::upper(trim($this->discount_code)),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
            ],

            'discount_description' => [
                'required',
                'string',
                'max:255',
            ],

            'discount_percent' => [
                'required',
                'integer',
                'min:1',
                'max:100',
            ],

            'max_discount_amount' => [
                'required',
                'numeric',
                'min:0',
            ],

            'min_order_value' => [
                'required',
                'numeric',
                'min:0',
            ],

            'usage_limit' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'used_count' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'expire_date' => [
                'required',
                'date',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'discount_code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('discounts', 'discount_code'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'discount_description.required' => 'Vui lòng nhập mô tả giảm giá.',
            'discount_description.max' => 'Mô tả giảm giá không được vượt quá 255 ký tự.',

            'discount_percent.required' => 'Vui lòng nhập phần trăm giảm giá.',
            'discount_percent.integer' => 'Phần trăm giảm giá phải là số nguyên.',
            'discount_percent.min' => 'Phần trăm giảm giá phải từ 1%.',
            'discount_percent.max' => 'Phần trăm giảm giá không được vượt quá 100%.',

            'max_discount_amount.required' => 'Vui lòng nhập số tiền giảm tối đa.',
            'max_discount_amount.numeric' => 'Số tiền giảm tối đa phải là số.',
            'max_discount_amount.min' => 'Số tiền giảm tối đa không được nhỏ hơn 0.',

            'min_order_value.required' => 'Vui lòng nhập giá trị đơn hàng tối thiểu.',
            'min_order_value.numeric' => 'Giá trị đơn hàng tối thiểu phải là số.',
            'min_order_value.min' => 'Giá trị đơn hàng tối thiểu không được nhỏ hơn 0.',

            'usage_limit.integer' => 'Giới hạn sử dụng phải là số nguyên.',
            'usage_limit.min' => 'Giới hạn sử dụng phải lớn hơn 0.',

            'used_count.integer' => 'Số lượt đã dùng phải là số nguyên.',
            'used_count.min' => 'Số lượt đã dùng không được nhỏ hơn 0.',

            'expire_date.required' => 'Vui lòng chọn ngày hết hạn.',
            'expire_date.date' => 'Ngày hết hạn không hợp lệ.',

            'user_id.exists' => 'Người dùng được chọn không tồn tại.',
            'discount_code.required' => 'Vui lòng nhập mã giảm giá.',
            'discount_code.max' => 'Mã giảm giá không được vượt quá 50 ký tự.',
            'discount_code.regex' => 'Mã giảm giá chỉ được chứa chữ in hoa, số, dấu gạch ngang hoặc gạch dưới.',
            'discount_code.unique' => 'Mã giảm giá này đã tồn tại.',
        ];
    }
}
