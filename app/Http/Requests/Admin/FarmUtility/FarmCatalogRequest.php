<?php

namespace App\Http\Requests\Admin\FarmUtility;

use Illuminate\Foundation\Http\FormRequest;

abstract class FarmCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'slug', 'description'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
        if (!$this->exists('aliases') || $this->input('aliases') === []) {
            $this->merge(['aliases' => null]);
        } elseif (is_array($this->input('aliases'))) {
            $this->merge(['aliases' => array_map(fn ($value) => is_string($value) ? trim($value) : $value, $this->input('aliases'))]);
        }
        if (!$this->exists('description')) $this->merge(['description' => null]);
    }

    protected function commonRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'aliases' => ['nullable', 'array', 'max:100'],
            'aliases.*' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên.',
            'name.unique' => 'Tên này đã tồn tại.',
            'slug.required' => 'Vui lòng nhập đường dẫn.',
            'slug.regex' => 'Đường dẫn chỉ gồm chữ thường không dấu, số và dấu gạch ngang.',
            'slug.unique' => 'Đường dẫn đã được sử dụng.',
            'aliases.array' => 'Tên gọi khác phải là danh sách.',
            'aliases.*.distinct' => 'Tên gọi khác không được trùng nhau.',
            'aliases.*.required' => 'Tên gọi khác không được để trống.',
            'crop_id.exists' => 'Cây trồng không tồn tại.',
        ];
    }
}