<?php

namespace App\Http\Requests\Client\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['bail', 'required', 'string', 'current_password'],
            'password' => [
                'bail', 'required', 'string', 'confirmed',
                Rules\Password::defaults(), 'different:current_password',
            ],
            'password_confirmation' => ['bail', 'required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Vui lòng nhập mật khẩu hiện tại.',
            'current_password.string' => 'Mật khẩu hiện tại không hợp lệ.',
            'current_password.current_password' => 'Mật khẩu hiện tại không đúng.',
            'password.required' => 'Vui lòng nhập mật khẩu mới.',
            'password.string' => 'Mật khẩu mới không hợp lệ.',
            'password.confirmed' => 'Xác nhận mật khẩu không khớp.',
            'password.different' => 'Mật khẩu mới phải khác mật khẩu hiện tại.',
            'password.min' => 'Mật khẩu mới phải có ít nhất :min ký tự.',
            'password_confirmation.required' => 'Vui lòng xác nhận mật khẩu mới.',
            'password_confirmation.string' => 'Xác nhận mật khẩu không hợp lệ.',
        ];
    }
}