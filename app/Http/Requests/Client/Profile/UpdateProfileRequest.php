<?php

namespace App\Http\Requests\Client\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        // Giữ nguyên dữ liệu sai kiểu để quy tắc string bắt lỗi, không ép mảng thành chữ.
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }

        if (is_string($this->input('phone_number'))) {
            $phone = trim($this->input('phone_number'));
            $this->merge(['phone_number' => $phone === '' ? null : $phone]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'phone_number' => [
                'bail', 'nullable', 'string', 'max:20',
                'regex:/^[0-9+\-\s()]{8,20}$/',
            ],
            'avatar' => [
                'bail', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập họ và tên.',
            'name.string' => 'Họ và tên không hợp lệ.',
            'name.max' => 'Họ và tên không được vượt quá 255 ký tự.',
            'phone_number.string' => 'Số điện thoại không hợp lệ.',
            'phone_number.regex' => 'Số điện thoại không hợp lệ.',
            'phone_number.max' => 'Số điện thoại không được vượt quá 20 ký tự.',
            'avatar.image' => 'Ảnh đại diện phải là hình ảnh.',
            'avatar.mimes' => 'Ảnh đại diện chỉ hỗ trợ JPG, JPEG, PNG hoặc WEBP.',
            'avatar.max' => 'Ảnh đại diện không được vượt quá 2MB.',
            'avatar.uploaded' => 'Không tải được ảnh lên. Vui lòng chọn lại ảnh tối đa 2MB.',
        ];
    }
}