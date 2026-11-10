<?php

namespace App\Http\Requests\Admin\FarmUtility;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CropRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Quyền được kiểm tra tại controller.
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'slug'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('crops', 'slug')->ignore($this->route('crop')?->id),
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên cây trồng.',
            'slug.required' => 'Vui lòng nhập đường dẫn cây trồng.',
            'slug.regex' => 'Đường dẫn chỉ gồm chữ thường không dấu, số và dấu gạch ngang.',
            'slug.unique' => 'Đường dẫn này đã được sử dụng.',
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'tên cây trồng', 'slug' => 'đường dẫn', 'is_active' => 'trạng thái'];
    }
}