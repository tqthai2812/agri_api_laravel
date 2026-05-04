<?php

namespace App\Http\Requests\Admin\Subcategory;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubcategoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
        // return auth()->user() && auth()->user()->role === 'admin';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $subcategoryId = $this->route('subcategory') ? $this->route('subcategory')->id : null;

        return [
            'category_id' => 'required|exists:categories,id',
            'subcategory_name' => 'required|string|max:255',
            'subcategory_slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('subcategories')->ignore($subcategoryId)
            ]
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Danh mục cha là bắt buộc.',
            'category_id.exists' => 'Danh mục cha không tồn tại.',
            'subcategory_name.required' => 'Tên danh mục con là bắt buộc.',
            'subcategory_name.string' => 'Tên danh mục con phải là một chuỗi.',
            'subcategory_name.max' => 'Tên danh mục con không được vượt quá 255 ký tự.',
            'subcategory_slug.string' => 'Slug phải là một chuỗi.',
            'subcategory_slug.max' => 'Slug không được vượt quá 255 ký tự.',
            'subcategory_slug.unique' => 'Slug đã tồn tại. Vui lòng chọn slug khác.',
        ];
    }
}
