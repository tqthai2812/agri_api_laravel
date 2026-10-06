<?php

namespace App\Repositories;

use App\Contracts\Repositories\ExpenseRepositoryInterface;
use App\Models\Expense;
use App\Support\DashboardPeriod;
use Illuminate\Database\Eloquent\Builder;

class ExpenseRepository implements ExpenseRepositoryInterface
{
    public function query(array $f = []): Builder
    {
        $q = Expense::query()->with([
            "category",
            "creator:id,name",
            "originalExpense:id,amount,incurred_at,status,category_id,reverses_expense_id",
            "reversal:id,reverses_expense_id,amount,incurred_at",
            "order:id,order_status,total_payment",
            "payment:id,order_id,transaction_id,status,amount",
            "inventoryDocument:id,document_number,document_type,status,order_id",
        ]);
        if (!empty($f["date_from"]) && !empty($f["date_to"])) {
            $p = new DashboardPeriod($f["date_from"], $f["date_to"]);
            $q->where("incurred_at", ">=", $p->storageStart())->where(
                "incurred_at",
                "<",
                $p->storageEnd(),
            );
        }
        if (!empty($f["category_id"])) {
            $q->where("category_id", $f["category_id"]);
        }
        if (!empty($f["status"])) {
            $q->where("status", $f["status"]);
        }
        if (!empty($f["order_id"])) {
            $q->where("order_id", $f["order_id"]);
        }
        if (($f["source"] ?? "") === "inventory") {
            $q->where("entry_key", "like", "inventory-adjustment:%");
        }
        if (($f["source"] ?? "") === "manual") {
            $q->where("entry_key", "like", "manual-expense:%");
        }
        if (($f["source"] ?? "") === "reversal") {
            $q->whereNotNull("reverses_expense_id");
        }
        if (in_array($f["payment_state"] ?? "", ["paid", "unpaid"], true)) {
            $q->where("entry_key", "like", "manual-expense:%")
                ->where("status", "posted")
                ->whereDoesntHave("reversal");
            $f["payment_state"] === "paid"
                ? $q->whereNotNull("paid_at")
                : $q->whereNull("paid_at");
        }
        $search = trim($f["search"] ?? "");
        if ($search !== "") {
            $q->where(function ($x) use ($search) {
                $x->where(
                    "description",
                    "like",
                    "%" . $search . "%",
                )->orWhereHas(
                    "category",
                    fn($c) => $c->where("name", "like", "%" . $search . "%"),
                );
                if (preg_match('/^(?:CP0*)?([0-9]+)$/i', $search, $m)) {
                    $x->orWhere("id", (int) $m[1]);
                }
            });
        }
        return $q;
    }
    public function detail(int $id): Expense
    {
        return $this->query()->findOrFail($id);
    }
}
