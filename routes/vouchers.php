<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\V1\DiscountLotController;
use App\Http\Controllers\Client\V1\VoucherController;

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('discount-lots', [DiscountLotController::class, 'index']);
});
Route::prefix('v1/public')->group(function () {
    Route::get('vouchers', [VoucherController::class, 'index'])->middleware('throttle:60,1');
});
