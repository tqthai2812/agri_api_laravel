<?php

use App\Http\Controllers\Admin\V1\DataExportController;
use Illuminate\Support\Facades\Route;

Route::prefix("api/v1/data-exports")
    ->middleware(["api", "auth:sanctum", "permission:export.view"])
    ->group(function () {
        Route::get("/", [DataExportController::class, "index"]);
        Route::post("/", [DataExportController::class, "store"])->middleware([
            "permission:export.create",
            "throttle:20,1",
        ]);
        Route::get("/{export}", [
            DataExportController::class,
            "show",
        ])->whereNumber("export");
        Route::get("/{export}/download", [
            DataExportController::class,
            "download",
        ])->whereNumber("export");
        Route::post("/{export}/retry", [DataExportController::class, "retry"])
            ->whereNumber("export")
            ->middleware(["permission:export.create", "throttle:10,1"]);
    });
