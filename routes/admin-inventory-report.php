<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\V1\InventoryReportController;

Route::prefix("api/v1/reports/inventory")
    ->middleware([
        "api",
        "auth:sanctum",
        "can:inventory.view",
        "can:report.inventory.view",
    ])
    ->group(function () {
        Route::get("/", [InventoryReportController::class, "show"]);
        Route::get("/packages/{package}/lots/{lot}/adjustment", [
            InventoryReportController::class,
            "adjustmentContext",
        ])->whereNumber(['package', 'lot'])->middleware('can:inventory.create');
        Route::get("/adjustment-events/{eventKey}", [
            InventoryReportController::class,
            "adjustmentEvent",
        ])->whereUuid('eventKey')->middleware('can:inventory.create');
        Route::get("/packages/{package}/journal", [
            InventoryReportController::class,
            "journal",
        ])->whereNumber("package");
        Route::get("/documents/{document}", [
            InventoryReportController::class,
            "document",
        ])->whereNumber("document");
    });
