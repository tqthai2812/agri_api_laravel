<?php

namespace App\Http\Requests\Admin\Category;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // return auth()->user() && auth()->user()->role === 'admin';
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $categoryId = $this->route('category') ? $this->route('category')->id : null;
        return [
            'category_name' => 'required|string|max:255',
            'category_description' => 'nullable|string',
            'category_slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('categories')->ignore($categoryId)
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'category_name.required' => 'Tên danh mục là bắt buộc.',
            'category_name.string' => 'Tên danh mục phải là một chuỗi.',
            'category_name.max' => 'Tên danh mục không được vượt quá 255 ký tự.',
            'category_slug.string' => 'Slug phải là một chuỗi.',
            'category_slug.max' => 'Slug không được vượt quá 255 ký tự.',
            'category_slug.unique' => 'Slug đã tồn tại. Vui lòng chọn slug khác.',
        ];
    }
}
