<?php

namespace App\Http\Requests\Admin\Finance;

use Illuminate\Foundation\Http\FormRequest;

class ExpenseWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }
    public function rules(): array
    {
        $a = $this->route()->defaults["expense_action"] ?? "create";
        $rules = ["request_key" => ["required", "uuid"]];
        if ($a !== "create") {
            $rules["lock_version"] = ["required", "integer", "min:0"];
        }
        if (in_array($a, ["create", "update"], true)) {
            $rules += [
                "category_id" => ["required", "integer", "min:1"],
                "amount" => [
                    "required",
                    "string",
                    'regex:/^[0-9]{1,16}(?:\.[0-9]{1,2})?$/D',
                ],
                "description" => ["required", "string", "max:2000"],
                "incurred_at" => ["required", "date_format:Y-m-d\TH:i"],
                "paid_at" => ["nullable", "date_format:Y-m-d\TH:i"],
                "order_id" => ["nullable", "integer", "min:1"],
                "payment_id" => ["nullable", "integer", "min:1"],
                "inventory_document_id" => ["nullable", "integer", "min:1"],
            ];
        }
        if ($a === "payment") {
            $rules += [
                "paid_at" => ["present", "nullable", "date_format:Y-m-d\TH:i"],
                "reason" => ["required", "string", "max:1000"],
            ];
        }
        if ($a === "reverse") {
            $rules += [
                "incurred_at" => ["required", "date_format:Y-m-d\TH:i"],
                "reason" => ["required", "string", "max:1000"],
            ];
        }
        if ($a === "cancel") {
            $rules += ["reason" => ["required", "string", "max:1000"]];
        }
        return $rules;
    }
    public function attributes(): array
    {
        return [
            "amount" => "số tiền",
            "category_id" => "danh mục",
            "description" => "nội dung",
            "incurred_at" => "ngày phát sinh",
            "paid_at" => "ngày trả tiền",
            "reason" => "lý do",
        ];
    }
}
