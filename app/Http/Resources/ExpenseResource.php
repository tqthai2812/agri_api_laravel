<?php

namespace App\Http\Resources;

use App\Support\ExpenseState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        $e = $this->resource;
        $manual = ExpenseState::manual($e);
        $inventory = ExpenseState::inventory($e);
        $reversed = (bool) $e->reversal;
        $editable = $manual && !$reversed && $e->status === "draft";
        $posted = $manual && !$reversed && $e->status === "posted";
        $can = fn($p) => (bool) $r->user()?->can($p);
        return [
            "id" => $e->id,
            "code" => "CP" . str_pad((string) $e->id, 6, "0", STR_PAD_LEFT),
            "lock_version" => (int) $e->lock_version,
            "category_id" => $e->category_id,
            "category" => $e->category?->only([
                "id",
                "code",
                "name",
                "is_active",
            ]),
            "order_id" => $e->order_id,
            "payment_id" => $e->payment_id,
            "inventory_document_id" => $e->inventory_document_id,
            "order_code" => $e->order_id
                ? "DH" . str_pad((string) $e->order_id, 6, "0", STR_PAD_LEFT)
                : null,
            "inventory_document_number" =>
            $e->inventoryDocument?->document_number,
            "amount" => (string) $e->amount,
            "incurred_at" => $e->incurred_at?->toIso8601String(),
            "paid_at" => $e->paid_at?->toIso8601String(),
            "status" => $e->status,
            "is_reversed" => $reversed,
            "reverses_expense_id" => $e->reverses_expense_id,
            "reversal_id" => $e->reversal?->id,
            "source" => $inventory
                ? "inventory"
                : ($e->reverses_expense_id
                    ? "reversal"
                    : ($manual
                        ? "manual"
                        : "legacy")),
            "payment_state" => $posted
                ? ($e->paid_at
                    ? "paid"
                    : "unpaid")
                : ($manual && $e->status === "draft"
                    ? "draft"
                    : "not_applicable"),
            "description" => $e->description,
            "creator" => $e->creator?->only(["id", "name"]),
            "can_edit" => $editable && $can("expense.update"),
            "can_cancel" => $editable && $can("expense.update"),
            "can_post" => $editable && $can("expense.post"),
            "can_pay" => $posted && $can("expense.pay"),
            "can_reverse" => $posted && $can("expense.reverse"),
            "created_at" => $e->created_at?->toIso8601String(),
            "updated_at" => $e->updated_at?->toIso8601String(),
        ];
    }
}
