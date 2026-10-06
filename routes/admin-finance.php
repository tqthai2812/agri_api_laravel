<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\V1\{
    ExpenseController,
    ExpenseCategoryController,
    FinanceEvidenceController,
    ProfitReportController,
};

Route::prefix("api/v1")
    ->middleware(["api", "auth:sanctum"])
    ->group(function () {
        Route::prefix("expenses")
            ->middleware("can:expense.view")
            ->group(function () {
                Route::get("/", [ExpenseController::class, "index"]);
                Route::get("/{expense}", [
                    ExpenseController::class,
                    "show",
                ])->whereNumber("expense");
                Route::post("/", [ExpenseController::class, "write"])
                    ->defaults("expense_action", "create")
                    ->middleware("can:expense.create");
                Route::put("/{expense}", [ExpenseController::class, "write"])
                    ->whereNumber("expense")
                    ->defaults("expense_action", "update")
                    ->middleware("can:expense.update");
                foreach (
                    [
                        "post" => "expense.post",
                        "cancel" => "expense.update",
                        "payment" => "expense.pay",
                        "reverse" => "expense.reverse",
                    ]
                    as $a => $p
                ) {
                    Route::post("/{expense}/" . $a, [
                        ExpenseController::class,
                        "write",
                    ])
                        ->whereNumber("expense")
                        ->defaults("expense_action", $a)
                        ->middleware("can:" . $p);
                }
            });
        Route::get("expense-categories", [
            ExpenseCategoryController::class,
            "index",
        ])->middleware("can:expense.view");
        Route::post("expense-categories", [
            ExpenseCategoryController::class,
            "store",
        ])->middleware(["can:expense.view", "can:expense-category.manage"]);
        Route::put("expense-categories/{category}", [
            ExpenseCategoryController::class,
            "update",
        ])
            ->whereNumber("category")
            ->middleware(["can:expense.view", "can:expense-category.manage"]);
        Route::get("finance/lookups/{kind}", [
            FinanceEvidenceController::class,
            "search",
        ])
            ->whereIn("kind", ["orders", "payments", "inventory-documents"])
            ->middleware("can:expense.view");
        Route::get("finance/evidence/orders/{order}", [
            FinanceEvidenceController::class,
            "order",
        ])
            ->whereNumber("order")
            ->middleware("can:order.view");
        Route::get("finance/evidence/inventory-documents/{document}", [
            FinanceEvidenceController::class,
            "document",
        ])
            ->whereNumber("document")
            ->middleware("can:inventory.view");
        Route::get("reports/profit", [
            ProfitReportController::class,
            "show",
        ])->middleware("can:report.profit.view");
        Route::get("reports/profit/export", [
            ProfitReportController::class,
            "export",
        ])->middleware(["can:report.profit.view", "can:report.profit.export"]);
    });
