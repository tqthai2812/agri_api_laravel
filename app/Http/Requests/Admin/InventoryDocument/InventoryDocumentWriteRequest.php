<?php

namespace App\Http\Requests\Admin\InventoryDocument;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class InventoryDocumentWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('suppliers', 'id')
                    ->where('is_active', true),
            ],

            'document_date' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],

            'note' => ['nullable', 'string', 'max:10000'],

            'items' => ['required', 'array', 'list', 'min:1', 'max:100'],

            'items.*' => [
                'required',
                'array:line_number,package_id,quantity_change,unit_cost,note',
            ],

            'items.*.line_number' => [
                'required',
                'integer',
                'between:1,2147483647',
                'distinct',
            ],

            'items.*.package_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('product_packages', 'id'),
            ],

            'items.*.quantity_change' => [
                'required',
                'integer',
                'between:1,2147483647',
            ],

            // Phiếu nháp được phép chưa biết giá vốn.
            // Service bắt buộc có giá vốn trước khi posted.
            'items.*.unit_cost' => [
                'nullable',
                'numeric',
                'min:0',
                'max:9999999999.99',
                'regex:/^\d{1,10}(?:\.\d{1,2})?$/D',
            ],

            'items.*.note' => ['nullable', 'string', 'max:10000'],

            // Các trường này do backend quản lý.
            'document_number' => ['prohibited'],
            'document_type' => ['prohibited'],
            'status' => ['prohibited'],
            'supplier_name' => ['prohibited'],
            'order_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'posted_by' => ['prohibited'],
            'posted_at' => ['prohibited'],
        ];
    }

    public function attributes(): array
    {
        return [
            'supplier_id' => 'nhà cung cấp',
            'document_date' => 'ngày chứng từ',
            'items' => 'danh sách dòng hàng',
            'items.*.line_number' => 'số dòng',
            'items.*.package_id' => 'quy cách sản phẩm',
            'items.*.quantity_change' => 'số lượng nhập',
            'items.*.unit_cost' => 'giá vốn đơn vị',
        ];
    }
}
