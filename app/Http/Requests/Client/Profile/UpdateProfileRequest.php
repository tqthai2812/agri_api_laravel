<?php

namespace App\Http\Requests\Client\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge([
                'name' => trim((string) $this->name),
            ]);
        }

        if ($this->has('phone_number')) {
            $this->merge([
                'phone_number' => trim((string) $this->phone_number),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            'phone_number' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^[0-9+\-\s()]{8,20}$/',
            ],

            'avatar' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập họ và tên.',
            'name.max' => 'Họ và tên không được vượt quá 255 ký tự.',

            'phone_number.regex' => 'Số điện thoại không hợp lệ.',
            'phone_number.max' => 'Số điện thoại không được vượt quá 20 ký tự.',

            'avatar.image' => 'Ảnh đại diện phải là hình ảnh.',
            'avatar.mimes' => 'Ảnh đại diện chỉ hỗ trợ JPG, JPEG, PNG hoặc WEBP.',
            'avatar.max' => 'Ảnh đại diện không được vượt quá 2MB.',
        ];
    }
}
