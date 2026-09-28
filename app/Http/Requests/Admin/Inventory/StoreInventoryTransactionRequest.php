<?php

namespace App\Http\Requests\Admin\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('quantity_change');

        if (
            (is_int($value) || is_string($value))
            && preg_match('/^-?\d+$/D', (string) $value)
        ) {
            $quantity = filter_var($value, FILTER_VALIDATE_INT);

            if (
                $quantity !== false
                && $quantity >= -2147483647
                && $quantity <= 2147483647
            ) {
                if ($this->input('transaction_type') === 'import') {
                    $quantity = abs($quantity);
                }

                $this->merge([
                    'quantity_change' => $quantity,
                ]);
            }
        }
    }

    public function rules(): array
    {
        $import = $this->input('transaction_type') === 'import';
        $adjustment = $this->input('transaction_type') === 'adjustment';
        $decrease = $adjustment
            && is_numeric($this->input('quantity_change'))
            && (float) $this->input('quantity_change') < 0;

        return [
            'event_key' => ['required', 'uuid'],

            'package_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('product_packages', 'id'),
            ],

            'transaction_type' => [
                'required',
                Rule::in(['import', 'adjustment']),
            ],

            'quantity_change' => [
                'required',
                'integer',
                'between:-2147483647,2147483647',
                'not_in:0',
                ...($import ? ['min:1'] : []),
            ],

            'note' => [
                Rule::requiredIf($adjustment),
                'nullable',
                'string',
                'max:1000',
            ],

            'supplier_id' => [
                Rule::requiredIf($import),
                Rule::prohibitedIf(!$import),
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')
                    ->where('is_active', true),
            ],

            'lot_id' => [
                Rule::requiredIf($adjustment),
                Rule::prohibitedIf(!$adjustment),
                'nullable',
                'integer',
                Rule::exists('inventory_lots', 'id')
                    ->where('package_id', $this->input('package_id')),
            ],

            'lot_code' => [
                Rule::requiredIf($import),
                Rule::prohibitedIf(!$import),
                'nullable',
                'string',
                'max:100',
            ],

            'manufactured_on' => [
                Rule::prohibitedIf(!$import),
                'nullable',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],

            'expires_on' => [
                Rule::prohibitedIf(!$import),
                'nullable',
                'date_format:Y-m-d',
            ],

            'unit_cost' => [
                Rule::requiredIf($import),
                Rule::prohibitedIf(!$import),
                'nullable',
                'numeric',
                'min:0',
                'max:9999999999.99',
                'regex:/^\d+(\.\d{1,2})?$/D',
            ],

            'lot_status' => [
                Rule::prohibitedIf(!$import),
                'sometimes',
                'required',
                Rule::in(['available', 'quarantined', 'blocked']),
            ],

            'expense_category_id' => [
                Rule::requiredIf($decrease),
                Rule::prohibitedIf(!$decrease),
                'nullable',
                'integer',
                Rule::exists('expense_categories', 'id')
                    ->where('is_active', true),
            ],

            // Không cho client gắn tùy ý một thao tác tay vào đơn bán.
            'order_id' => ['prohibited'],
            'order_item_id' => ['prohibited'],
            'document_item_id' => ['prohibited'],
            'performed_by' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $manufactured = $this->input('manufactured_on');
                $expires = $this->input('expires_on');

                if ($manufactured && $expires && $expires < $manufactured) {
                    $validator->errors()->add(
                        'expires_on',
                        'Hạn dùng không được trước ngày sản xuất.'
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'event_key.required' => 'Thiếu mã định danh thao tác kho.',
            'event_key.uuid' => 'Mã định danh thao tác phải là UUID.',

            'package_id.required' => 'Vui lòng chọn quy cách sản phẩm.',
            'package_id.exists' => 'Quy cách sản phẩm không tồn tại.',

            'quantity_change.required' => 'Vui lòng nhập số lượng thay đổi.',
            'quantity_change.integer' => 'Số lượng phải là số nguyên.',
            'quantity_change.not_in' => 'Số lượng thay đổi phải khác 0.',
            'quantity_change.between' => 'Số lượng vượt giới hạn cho phép.',

            'transaction_type.in' =>
            'Chỉ hỗ trợ nhập kho hoặc điều chỉnh tại đây. Xuất bán phải thực hiện từ đơn hàng.',

            'supplier_id.required' => 'Nhập kho phải chọn nhà cung cấp.',
            'supplier_id.exists' => 'Nhà cung cấp không tồn tại hoặc đã ngừng hoạt động.',

            'lot_id.required' => 'Điều chỉnh phải chọn lô.',
            'lot_id.exists' => 'Lô không thuộc quy cách đã chọn.',

            'lot_code.required' => 'Vui lòng nhập mã lô.',
            'unit_cost.required' => 'Phiếu nhập phải có giá vốn đơn vị.',
            'unit_cost.regex' => 'Giá vốn nhập tối đa 2 chữ số thập phân.',

            'expense_category_id.required' =>
            'Điều chỉnh giảm phải chọn nhóm chi phí hao hụt/điều chỉnh.',
            'expense_category_id.exists' =>
            'Nhóm chi phí không tồn tại hoặc không hoạt động.',

            'note.required' => 'Điều chỉnh tồn phải có lý do.',
            'note.max' => 'Ghi chú không được vượt quá 1000 ký tự.',
        ];
    }
}
