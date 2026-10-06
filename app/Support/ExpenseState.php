<?php

namespace App\Support;

use App\Models\Expense;

final class ExpenseState
{
    public static function manual(Expense $e): bool
    {
        return str_starts_with((string) $e->entry_key, "manual-expense:");
    }
    public static function inventory(Expense $e): bool
    {
        return str_starts_with((string) $e->entry_key, "inventory-adjustment:");
    }
    public static function snapshot(Expense $e): array
    {
        return array_intersect_key(
            $e->getAttributes(),
            array_flip([
                "category_id",
                "order_id",
                "payment_id",
                "inventory_document_id",
                "reverses_expense_id",
                "amount",
                "incurred_at",
                "paid_at",
                "status",
                "description",
                "lock_version",
            ]),
        );
    }
}
