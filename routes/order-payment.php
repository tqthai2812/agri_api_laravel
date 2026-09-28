<?php

use App\Http\Controllers\Admin\V1\OrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function () {
        Route::post(
            'orders/{order}/confirm-cod-payment',
            [OrderController::class, 'confirmCodPayment']
        )->whereNumber('order');
    });
