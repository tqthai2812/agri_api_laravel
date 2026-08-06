<?php

namespace App\Http\Requests\Admin\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('variants') && is_string($this->variants)) {
            $this->merge([
                'variants' => json_decode($this->variants, true),
            ]);
        }

        if ($this->has('is_show')) {
            $this->merge([
                'is_show' => filter_var($this->is_show, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }

        if ($this->has('replace_images')) {
            $this->merge([
                'replace_images' => filter_var($this->replace_images, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }

        if ($this->has('primary_image_index')) {
            $this->merge([
                'primary_image_index' => (int) $this->primary_image_index,
            ]);
        }
    }

    public function rules(): array
    {
        $productId = $this->getProductId();

        return [
            'category_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('categories', 'id'),
            ],

            'subcategory_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('subcategories', 'id')->where(function ($query) {
                    if ($this->filled('category_id')) {
                        return $query->where('category_id', $this->input('category_id'));
                    }

                    return $query;
                }),
            ],

            'origin_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('origins', 'id'),
            ],

            'product_name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'usage_instructions' => [
                'nullable',
                'string',
            ],

            'safety_warning' => [
                'nullable',
                'string',
            ],

            'is_show' => [
                'nullable',
                'boolean',
            ],

            'replace_images' => [
                'nullable',
                'boolean',
            ],

            'images' => [
                'nullable',
                'array',
                'max:8',
            ],

            'images.*' => [
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],

            'primary_image_index' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'variants' => [
                'sometimes',
                'required',
                'array',
                'min:1',
            ],

            'variants.*.variant_name' => [
                'required_with:variants',
                'string',
                'max:255',
            ],

            'variants.*.packages' => [
                'required_with:variants',
                'array',
                'min:1',
            ],

            'variants.*.packages.*.sku' => [
                'required_with:variants',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($productId) {
                    $exists = DB::table('product_packages')
                        ->join('product_variants', 'product_packages.variant_id', '=', 'product_variants.id')
                        ->where('product_packages.sku', $value)
                        ->where('product_variants.product_id', '!=', $productId)
                        ->exists();

                    if ($exists) {
                        $fail('SKU này đã tồn tại ở sản phẩm khác.');
                    }
                },
            ],

            'variants.*.packages.*.size' => [
                'required_with:variants',
                'numeric',
                'min:0',
            ],

            'variants.*.packages.*.unit' => [
                'required_with:variants',
                Rule::in(['kg', 'g', 'ml', 'l', 'piece']),
            ],

            'variants.*.packages.*.price' => [
                'required_with:variants',
                'numeric',
                'min:0',
            ],

            'variants.*.packages.*.quantity_available' => [
                'required_with:variants',
                'integer',
                'min:0',
            ],

            'variants.*.packages.*.barcode' => [
                'nullable',
                'string',
                'max:255',
            ],

            'variants.*.packages.*.box_barcode' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Vui lòng chọn danh mục.',
            'category_id.exists' => 'Danh mục không tồn tại.',

            'subcategory_id.required' => 'Vui lòng chọn danh mục con.',
            'subcategory_id.exists' => 'Danh mục con không tồn tại hoặc không thuộc danh mục đã chọn.',

            'origin_id.required' => 'Vui lòng chọn xuất xứ.',
            'origin_id.exists' => 'Xuất xứ không tồn tại.',

            'product_name.required' => 'Vui lòng nhập tên sản phẩm.',
            'product_name.max' => 'Tên sản phẩm không được vượt quá 255 ký tự.',

            'images.array' => 'Danh sách ảnh không hợp lệ.',
            'images.max' => 'Chỉ được tải lên tối đa 8 ảnh.',
            'images.*.image' => 'File tải lên phải là hình ảnh.',
            'images.*.mimes' => 'Ảnh phải có định dạng jpg, jpeg, png hoặc webp.',
            'images.*.max' => 'Mỗi ảnh không được vượt quá 5MB.',

            'variants.required' => 'Vui lòng thêm ít nhất một biến thể sản phẩm.',
            'variants.array' => 'Dữ liệu biến thể không hợp lệ.',

            'variants.*.variant_name.required_with' => 'Vui lòng nhập tên biến thể.',
            'variants.*.variant_name.max' => 'Tên biến thể không được vượt quá 255 ký tự.',

            'variants.*.packages.required_with' => 'Mỗi biến thể cần có ít nhất một quy cách bán.',
            'variants.*.packages.array' => 'Dữ liệu quy cách không hợp lệ.',

            'variants.*.packages.*.sku.required_with' => 'Vui lòng nhập SKU.',

            'variants.*.packages.*.size.required_with' => 'Vui lòng nhập kích thước/quy cách.',
            'variants.*.packages.*.size.numeric' => 'Kích thước phải là số.',

            'variants.*.packages.*.unit.required_with' => 'Vui lòng chọn đơn vị.',
            'variants.*.packages.*.unit.in' => 'Đơn vị không hợp lệ.',

            'variants.*.packages.*.price.required_with' => 'Vui lòng nhập giá.',
            'variants.*.packages.*.price.numeric' => 'Giá phải là số.',

            'variants.*.packages.*.quantity_available.required_with' => 'Vui lòng nhập số lượng tồn kho.',
            'variants.*.packages.*.quantity_available.integer' => 'Số lượng tồn kho phải là số nguyên.',
        ];
    }

    private function getProductId(): int
    {
        $routeProduct = $this->route('product') ?? $this->route('id');

        if (is_object($routeProduct) && method_exists($routeProduct, 'getKey')) {
            return (int) $routeProduct->getKey();
        }

        return (int) $routeProduct;
    }
}
