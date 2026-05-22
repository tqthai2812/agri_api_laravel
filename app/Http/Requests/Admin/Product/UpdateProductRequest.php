<?php

namespace App\Http\Requests\Admin\Product;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Adjust this as needed for your authentication logic
        // return $this->user() && $this->user()->role === 'admin';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category_id' => 'sometimes|exists:categories,id',
            'subcategory_id' => 'sometimes|exists:subcategories,id',
            'origin_id' => 'sometimes|exists:origins,id',
            'product_name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'usage_instructions' => 'nullable|string',
            'safety_warning' => 'nullable|string',
            'is_show' => 'boolean',
            'replace_images' => 'boolean',
            'images' => 'array',
            'images.*' => 'image|mimes:jpeg,png,jpg|max:2048',
            'primary_image_index' => 'nullable|integer|min:0',
            'variants' => 'array',
            'variants.*.variant_name' => 'required_with:variants|string|max:255',
            'variants.*.packages' => 'array|min:1',
            'variants.*.packages.*.sku' => 'required_with:variants|string',
            'variants.*.packages.*.size' => 'required_with:variants|numeric|min:0',
            'variants.*.packages.*.unit' => ['required_with:variants', Rule::in(['kg', 'g', 'ml', 'l', 'piece'])],
            'variants.*.packages.*.price' => 'required_with:variants|numeric|min:0',
            'variants.*.packages.*.quantity_available' => 'required_with:variants|integer|min:0',
            'variants.*.packages.*.barcode' => 'nullable|string',
            'variants.*.packages.*.box_barcode' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'Danh mục không tồn tại',
            'subcategory_id.exists' => 'Danh mục con không tồn tại',
            'origin_id.exists' => 'Xuất xứ không tồn tại',
            'description.string' => 'Mô tả phải là một chuỗi',
            'usage_instructions.string' => 'Hướng dẫn sử dụng phải là một chuỗi',
            'safety_warning.string' => 'Cảnh báo an toàn phải là một chuỗi',
            'images.array' => 'Hình ảnh phải là một mảng',
            'images.*.image' => 'Mỗi mục phải là một hình ảnh',
            'images.*.mimes' => 'Hình ảnh phải có định dạng jpeg, png, jpg',
            'images.*.max' => 'Kích thước hình ảnh không được vượt quá 2048KB',
            'primary_image_index.integer' => 'Chỉ số hình ảnh chính phải là một số nguyên',
            'primary_image_index.min' => 'Chỉ số hình ảnh chính phải là một số nguyên không âm',
            'variants.*.variant_name.required_with' => 'Tên biến thể là bắt buộc',
            'variants.*.packages.*.sku.required_with' => 'SKU là bắt buộc',
            'variants.*.packages.*.size.required_with' => 'Kích thước là bắt buộc',
            'variants.*.packages.*.unit.required_with' => 'Đơn vị là bắt buộc',
            'variants.*.packages.*.price.required_with' => 'Giá là bắt buộc',
            'variants.*.packages.*.quantity_available.required_with' => 'Số lượng có sẵn là bắt buộc',
            'variants.*.packages.*.barcode.nullable' => 'Mã vạch phải là một chuỗi',
            'variants.*.packages.*.box_barcode.nullable' => 'Mã vạch hộp phải là một chuỗi',

        ];
    }
}
