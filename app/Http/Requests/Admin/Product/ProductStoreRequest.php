<?php

namespace App\Http\Requests\Admin\Product;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // return $this->user() && $this->user()->role === 'admin';
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'product_name'     => 'required|string|max:255',
            'description'      => 'nullable|string',
            'usage_instructions' => 'nullable|string',
            'safety_warning'   => 'nullable|string',
            'category_id'      => 'required|exists:categories,id',
            'subcategory_id'   => 'nullable|exists:subcategories,id',
            'origin_id'        => 'nullable|exists:origins,id',
            'is_show'          => 'boolean',

            // Ảnh
            'images'           => 'array|required',
            'images.*'         => 'image|mimes:jpeg,png,jpg|max:2048',

            // Variants & Packages (dạng JSON)
            'variants'         => 'required|array|min:1',
            'variants.*.variant_name' => 'required|string|max:255',
            'variants.*.packages'     => 'required|array|min:1',
            'variants.*.packages.*.size'   => 'required|numeric|min:0',
            'variants.*.packages.*.unit'   => ['required', Rule::in(['kg', 'g', 'ml', 'l', 'piece'])],
            'variants.*.packages.*.price'  => 'required|numeric|min:0',
            'variants.*.packages.*.quantity_available' => 'required|integer|min:0',
            'variants.*.packages.*.sku'    => 'nullable|string|unique:product_packages,sku',
            'variants.*.packages.*.barcode' => 'nullable|string',
            'variants.*.packages.*.box_barcode' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'product_name.required' => 'Tên sản phẩm là bắt buộc.',
            'product_name.string' => 'Tên sản phẩm phải là một chuỗi.',
            'product_name.max' => 'Tên sản phẩm không được vượt quá 255 ký tự.',
            'category_id.required' => 'Danh mục là bắt buộc.',
            'category_id.exists' => 'Danh mục không tồn tại.',
            'subcategory_id.exists' => 'Danh mục con không tồn tại.',
            'origin_id.exists' => 'Xuất xứ không tồn tại.',
            'is_show.boolean' => 'Trạng thái hiển thị phải là true hoặc false.',
            'images.array' => 'Ảnh phải là một mảng.',
            'images.required' => 'Phải có ít nhất một ảnh sản phẩm.',
            'images.*.image' => 'Mỗi phần tử trong ảnh phải là một file ảnh hợp lệ.',
            'images.*.mimes' => 'Ảnh phải có định dạng jpeg, png, jpg.',
            'images.*.max' => 'Kích thước ảnh không được vượt quá 2MB.',
            'variants.required' => 'Phải có ít nhất một variant.',
            'variants.array' => 'Variants phải là một mảng.',
            'variants.*.variant_name.required' => 'Tên variant là bắt buộc.',
            'variants.*.variant_name.string' => 'Tên variant phải là một chuỗi.',
            'variants.*.variant_name.max' => 'Tên variant không được vượt quá 255 ký tự.',
            'variants.*.packages.required' => 'Mỗi variant phải có ít nhất một package.',
            'variants.*.packages.array' => 'Packages phải là một mảng.',
            // Thêm các thông báo lỗi cho các trường packages nếu cần
        ];
    }
}
