<?php

namespace App\Http\Requests\Admin\Product;

use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class ProductWriteRequest extends FormRequest
{
    protected bool $updating = false;

    public function authorize(): bool
    {
        // Permission được kiểm tra tại Controller.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('variants'))) {
            $decoded = json_decode($this->input('variants'), true);

            // JSON sai vẫn phải bị validation từ chối.
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->merge(['variants' => $decoded]);
            }
        }

        foreach (['is_show', 'replace_images'] as $field) {
            if (!$this->exists($field)) {
                continue;
            }

            $value = $this->input($field);

            if (is_string($value)) {
                $normalized = strtolower(trim($value));

                if (in_array($normalized, ['true', '1'], true)) {
                    $this->merge([$field => true]);
                } elseif (in_array($normalized, ['false', '0'], true)) {
                    $this->merge([$field => false]);
                }
            }
        }

        // Không ép primary_image_index thành int:
        // "abc" phải bị từ chối, không được biến thành 0.
    }

    public function rules(): array
    {
        $required = $this->updating
            ? ['sometimes', 'required']
            : ['required'];

        $textRules = [
            'nullable',
            'string',
            function ($attribute, $value, $fail) {
                if (is_string($value) && strlen($value) > 65535) {
                    $fail('Nội dung vượt dung lượng cột TEXT của database.');
                }
            },
        ];

        return [
            'category_id' => [
                ...$required,
                'integer',
                Rule::exists('categories', 'id'),
            ],
            'subcategory_id' => [
                ...$required,
                'integer',
                Rule::exists('subcategories', 'id'),
            ],
            'origin_id' => [
                ...$required,
                'integer',
                Rule::exists('origins', 'id'),
            ],
            'product_name' => [
                ...$required,
                'string',
                'max:255',
            ],
            'brand' => ['nullable', 'string', 'max:255'],
            'description' => $textRules,
            'usage_instructions' => $textRules,
            'safety_warning' => $textRules,

            'is_show' => ['sometimes', 'required', 'boolean'],
            'replace_images' => ['sometimes', 'required', 'boolean'],

            'images' => ['nullable', 'array', 'list', 'max:8'],
            'images.*' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
            'primary_image_index' => ['nullable', 'integer', 'min:0'],

            'variants' => [
                ...$required,
                'array',
                'list',
                'min:1',
            ],
            'variants.*' => ['required', 'array'],
            'variants.*.id' => $this->updating
                ? ['nullable', 'integer', 'min:1', 'distinct']
                : ['prohibited'],
            'variants.*.variant_name' => [
                'required',
                'string',
                'max:255',
            ],
            'variants.*.packages' => [
                'required',
                'array',
                'list',
                'min:1',
            ],
            'variants.*.packages.*' => ['required', 'array'],
            'variants.*.packages.*.id' => $this->updating
                ? ['nullable', 'integer', 'min:1', 'distinct']
                : ['prohibited'],
            'variants.*.packages.*.sku' => [
                'required',
                'string',
                'max:255',
                'distinct:ignore_case',
            ],
            'variants.*.packages.*.size' => [
                'required',
                'numeric',
                'min:0',
                'max:999999.99',
                'regex:/^\d+(?:\.\d{1,2})?$/',
            ],
            'variants.*.packages.*.unit' => [
                'required',
                Rule::in(['kg', 'g', 'ml', 'l', 'piece']),
            ],
            'variants.*.packages.*.price' => [
                'required',
                'numeric',
                'min:0',
                'max:9999999999.99',
                'regex:/^\d+(?:\.\d{1,2})?$/',
            ],
            'variants.*.packages.*.quantity_available' => [
                'sometimes',
                'required',
                'integer',
                'min:0',
                'max:2147483647',
            ],
            'variants.*.packages.*.reorder_level' => [
                'sometimes',
                'required',
                'integer',
                'min:0',
                'max:4294967295',
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $product = $this->updating
                ? Product::findOrFail($this->productId())
                : null;

            // Kiểm tra theo giá trị cuối cùng sau PATCH.
            $categoryId = $this->input('category_id', $product?->category_id);
            $subcategoryId = $this->input(
                'subcategory_id',
                $product?->subcategory_id
            );

            $subcategoryMatches = \App\Models\Subcategory::query()
                ->whereKey($subcategoryId)
                ->where('category_id', $categoryId)
                ->exists();

            if (!$subcategoryMatches) {
                $validator->errors()->add(
                    'subcategory_id',
                    'Danh mục con không thuộc danh mục đã chọn.'
                );
            }

            $images = $this->file('images', []);

            if ($this->filled('primary_image_index')) {
                if (
                    empty($images)
                    || (int) $this->input('primary_image_index') >= count($images)
                ) {
                    $validator->errors()->add(
                        'primary_image_index',
                        'Chỉ số ảnh chính phải nằm trong danh sách ảnh tải lên.'
                    );
                }
            }

            if ($this->updating) {
                if (!empty($images) && !$this->boolean('replace_images')) {
                    $validator->errors()->add(
                        'replace_images',
                        'Khi gửi ảnh mới, cần gửi replace_images=true.'
                    );
                }

                if ($this->boolean('replace_images') && empty($images)) {
                    $validator->errors()->add(
                        'images',
                        'Vui lòng gửi ảnh mới khi chọn thay ảnh.'
                    );
                }
            }

            foreach ($this->input('variants', []) as $vi => $variant) {
                $variantId = $variant['id'] ?? null;

                if ($variantId) {
                    $owned = ProductVariant::query()
                        ->whereKey($variantId)
                        ->where('product_id', $product?->id)
                        ->exists();

                    if (!$owned) {
                        $validator->errors()->add(
                            "variants.$vi.id",
                            'Biến thể không thuộc sản phẩm đang sửa.'
                        );
                    }
                }

                foreach ($variant['packages'] as $pi => $package) {
                    $path = "variants.$vi.packages.$pi";
                    $packageId = $package['id'] ?? null;
                    $existing = null;

                    if ($packageId) {
                        $existing = ProductPackage::query()
                            ->whereKey($packageId)
                            ->where('variant_id', $variantId)
                            ->whereHas('variant', function ($query) use ($product) {
                                $query->where('product_id', $product?->id);
                            })
                            ->first();

                        if (!$existing) {
                            $validator->errors()->add(
                                "$path.id",
                                'Quy cách không thuộc biến thể của sản phẩm đang sửa.'
                            );
                        }
                    }

                    // Chỉ loại trừ ID đã xác minh quyền sở hữu.
                    $skuQuery = ProductPackage::query()
                        ->where('sku', $package['sku']);

                    if ($existing) {
                        $skuQuery->where('id', '!=', $existing->id);
                    }

                    if ($skuQuery->exists()) {
                        $validator->errors()->add(
                            "$path.sku",
                            'SKU đã tồn tại. Quy cách cũ cần gửi đúng id.'
                        );
                    }

                    // Quy cách mới chỉ được bắt đầu với tồn 0.
                    if (
                        !$packageId
                        && (int) ($package['quantity_available'] ?? 0) !== 0
                    ) {
                        $validator->errors()->add(
                            "$path.quantity_available",
                            'Quy cách mới có tồn 0. Hãy nhập hàng qua chức năng kho.'
                        );
                    }
                }
            }
        });
    }

    protected function productId(): int
    {
        $product = $this->route('product') ?? $this->route('id');

        return $product instanceof Product
            ? (int) $product->getKey()
            : (int) $product;
    }

    public function messages(): array
    {
        return [
            'required' => 'Trường :attribute là bắt buộc.',
            'integer' => 'Trường :attribute phải là số nguyên.',
            'numeric' => 'Trường :attribute phải là số.',
            'boolean' => 'Trường :attribute phải là true/false hoặc 1/0.',
            'exists' => 'Giá trị :attribute không tồn tại.',
            'distinct' => 'Trường :attribute bị trùng trong dữ liệu gửi lên.',
            'list' => 'Trường :attribute phải là danh sách liên tiếp từ chỉ số 0.',
            'variants.required' => 'Vui lòng thêm ít nhất một biến thể.',
            'variants.*.packages.required' => 'Mỗi biến thể cần có quy cách.',
            'variants.*.packages.*.size.regex' =>
            'Quy cách chỉ được có tối đa 2 chữ số thập phân.',
            'variants.*.packages.*.price.regex' =>
            'Giá chỉ được có tối đa 2 chữ số thập phân.',
            'images.max' => 'Chỉ được tải lên tối đa 8 ảnh.',
            'images.*.max' => 'Mỗi ảnh không được vượt quá 5MB.',
            'images.*.mimes' => 'Ảnh phải là jpg, jpeg, png hoặc webp.',
        ];
    }
}
