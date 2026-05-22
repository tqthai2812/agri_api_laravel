<?php

namespace App\Http\Requests\Admin\Product;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
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
            'category_id' => 'required|exists:categories,id',
            'subcategory_id' => 'required|exists:subcategories,id',
            'origin_id' => 'required|exists:origins,id',
            'product_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'usage_instructions' => 'nullable|string',
            'safety_warning' => 'nullable|string',
            'is_show' => 'boolean',
            'images' => 'array|min:1',
            'images.*' => 'image|mimes:jpeg,png,jpg|max:2048',
            'primary_image_index' => 'nullable|integer|min:0',
            'variants' => 'array|min:1',
            'variants.*.variant_name' => 'required|string|max:255',
            'variants.*.packages' => 'array|min:1',
            'variants.*.packages.*.sku' => 'required|string|unique:product_packages,sku',
            'variants.*.packages.*.size' => 'required|numeric|min:0',
            'variants.*.packages.*.unit' => ['required', Rule::in(['kg', 'g', 'ml', 'l', 'piece'])],
            'variants.*.packages.*.price' => 'required|numeric|min:0',
            'variants.*.packages.*.quantity_available' => 'required|integer|min:0',
            'variants.*.packages.*.barcode' => 'nullable|string',
            'variants.*.packages.*.box_barcode' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Vui lòng chọn danh mục',
            'category_id.exists' => 'Danh mục không tồn tại',
            'subcategory_id.required' => 'Vui lòng chọn danh mục con',
            'subcategory_id.exists' => 'Danh mục con không tồn tại',
            'origin_id.required' => 'Vui lòng chọn xuất xứ',
            'origin_id.exists' => 'Xuất xứ không tồn tại',
            'product_name.required' => 'Tên sản phẩm là bắt buộc',
            'product_name.max' => 'Tên sản phẩm không được vượt quá 255 ký tự',
            'images.array' => 'Hình ảnh phải là một mảng',
            'images.min' => 'Phải có ít nhất một hình ảnh',
            'images.*.image' => 'Mỗi phần tử phải là một hình ảnh hợp lệ',
            'images.*.mimes' => 'Hình ảnh phải có định dạng jpeg, png hoặc jpg',
            'images.*.max' => 'Kích thước hình ảnh không được vượt quá 2MB',
            'primary_image_index.integer' => 'Chỉ số hình ảnh chính phải là một số nguyên',
            'primary_image_index.min' => 'Chỉ số hình ảnh chính phải lớn hơn hoặc bằng 0',
            'variants.array' => 'Biến thể phải là một mảng',
            'variants.min' => 'Phải có ít nhất một biến thể',
            'variants.*.variant_name.required' => 'Tên biến thể là bắt buộc',
            'variants.*.variant_name.max' => 'Tên biến thể không được vượt quá 255 ký tự',
            'variants.*.packages.array' => 'Gói hàng phải là một mảng',
            'variants.*.packages.min' => 'Phải có ít nhất một gói hàng',
            'variants.*.packages.*.sku.required' => 'SKU là bắt buộc',
            'variants.*.packages.*.sku.unique' => 'SKU đã tồn tại',
            'variants.*.packages.*.size.required' => 'Kích thước là bắt buộc',
            'variants.*.packages.*.size.numeric' => 'Kích thước phải là một số',
            'variants.*.packages.*.size.min' => 'Kích thước phải lớn hơn hoặc bằng 0',
            'variants.*.packages.*.unit.required' => 'Đơn vị là bắt buộc',
            'variants.*.packages.*.unit.in' => 'Đơn vị phải là một trong các giá trị: kg, g, ml, l, piece',
            'variants.*.packages.*.price.required' => 'Giá là bắt buộc',
            'variants.*.packages.*.price.numeric' => 'Giá phải là một số',
            'variants.*.packages.*.price.min' => 'Giá phải lớn hơn hoặc bằng 0',
            'variants.*.packages.*.quantity_available.required' => 'Số lượng có sẵn là bắt buộc',
            'variants.*.packages.*.quantity_available.integer' => 'Số lượng có sẵn phải là một số nguyên',
            'variants.*.packages.*.quantity_available.min' => 'Số lượng có sẵn phải lớn hơn hoặc bằng 0',
        ];
    }
}
