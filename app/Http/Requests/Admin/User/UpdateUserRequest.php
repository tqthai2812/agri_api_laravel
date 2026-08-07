<?php

namespace App\Http\Requests\Admin\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('roles') && is_string($this->roles)) {
            $this->merge([
                'roles' => json_decode($this->roles, true),
            ]);
        }

        if ($this->has('is_active')) {
            $this->merge([
                'is_active' => filter_var($this->is_active, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    public function rules(): array
    {
        $userId = $this->getUserId();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],

            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],

            'phone_number' => ['nullable', 'string', 'max:20'],

            'password' => ['nullable', 'string', 'min:8', 'confirmed'],

            'avatar' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],

            'is_active' => ['nullable', 'boolean'],

            'roles' => ['nullable', 'array'],

            'roles.*' => [
                'string',
                Rule::exists('roles', 'name')->where('guard_name', 'web'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên người dùng.',
            'email.required' => 'Vui lòng nhập email.',
            'email.email' => 'Email không hợp lệ.',
            'email.unique' => 'Email này đã tồn tại.',
            'password.min' => 'Mật khẩu phải có ít nhất 8 ký tự.',
            'password.confirmed' => 'Xác nhận mật khẩu không khớp.',
            'avatar.image' => 'Avatar phải là hình ảnh.',
            'avatar.mimes' => 'Avatar phải có định dạng jpg, jpeg, png hoặc webp.',
            'avatar.max' => 'Avatar không được vượt quá 2MB.',
            'roles.*.exists' => 'Vai trò không tồn tại.',
        ];
    }

    private function getUserId(): int
    {
        $routeUser = $this->route('user');

        if (is_object($routeUser) && method_exists($routeUser, 'getKey')) {
            return (int) $routeUser->getKey();
        }

        return (int) $routeUser;
    }
}
