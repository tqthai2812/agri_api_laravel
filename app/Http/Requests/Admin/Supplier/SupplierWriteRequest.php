<?php

namespace App\Http\Requests\Admin\Supplier;

use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class SupplierWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Quyền cụ thể được kiểm tra tại middleware Controller.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        foreach (
            [
                'supplier_code',
                'name',
                'contact_name',
                'phone',
                'email',
                'tax_code',
            ] as $field
        ) {
            if (is_string($this->input($field))) {
                $data[$field] = trim($this->input($field));
            }
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        $supplier = $this->route('supplier');

        $isUpdate = $supplier instanceof Supplier;
        $presence = $isUpdate ? 'sometimes' : 'required';

        $codeUnique = Rule::unique('suppliers', 'supplier_code');
        $nameUnique = Rule::unique('suppliers', 'name');

        if ($isUpdate) {
            $codeUnique->ignore($supplier);
            $nameUnique->ignore($supplier);
        }

        return [
            'supplier_code' => [
                $presence,
                'required',
                'string',
                'max:50',
                $codeUnique,
            ],
            'name' => [
                $presence,
                'required',
                'string',
                'max:255',
                $nameUnique,
            ],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'tax_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'note' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'is_active' => ['sometimes', 'required', 'boolean'],

            'product_ids' => ['sometimes', 'array', 'list', 'max:500'],
            'product_ids.*' => [
                'required',
                'integer',
                'min:1',
                'distinct',
                Rule::exists('products', 'id'),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'supplier_code' => 'mã nhà cung cấp',
            'name' => 'tên nhà cung cấp',
            'contact_name' => 'người liên hệ',
            'phone' => 'số điện thoại',
            'email' => 'email',
            'address' => 'địa chỉ',
            'tax_code' => 'mã số thuế',
            'note' => 'ghi chú',
            'is_active' => 'trạng thái hoạt động',
            'product_ids' => 'danh sách sản phẩm',
            'product_ids.*' => 'sản phẩm',
        ];
    }
}
