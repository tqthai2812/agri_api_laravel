<?php

use App\Http\Controllers\Client\V1\OrderReviewController;
use App\Http\Controllers\Client\V1\PublicProductReviewController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::get('my-orders/{order}/reviews', [OrderReviewController::class, 'show'])
        ->whereNumber('order');

    Route::post('my-orders/{order}/reviews', [OrderReviewController::class, 'store'])
        ->whereNumber('order')->middleware('throttle:30,1');
});

Route::prefix('api/v1/public')->middleware('api')->group(function () {
    Route::get('products/{product}/reviews', [PublicProductReviewController::class, 'index'])
        ->whereNumber('product');
});
