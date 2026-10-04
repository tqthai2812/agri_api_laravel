<?php

use App\Http\Controllers\Admin\V1\OrderController;
use App\Http\Controllers\Client\V1\VnpayController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware('api')->group(function () {
    Route::get('payments/vnpay/ipn', [VnpayController::class, 'ipn']);
    Route::get('payments/vnpay/return', [VnpayController::class, 'returned']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('orders/{order}/confirm-cod-payment', [OrderController::class, 'confirmCodPayment'])
            ->whereNumber('order');
        Route::post('my-orders/{order}/vnpay/pay', [VnpayController::class, 'pay'])
            ->whereNumber('order')->middleware('throttle:6,1');
        Route::post('my-orders/{order}/vnpay/check', [VnpayController::class, 'check'])
            ->whereNumber('order')->middleware('throttle:6,1');
    });
});
