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

// Các route phía khách ở trên vẫn dùng nguyên quyền sở hữu đơn hàng.
Route::prefix('api/v1/reviews')
    ->middleware(['api', 'auth:sanctum', 'permission:review.view'])
    ->controller(\App\Http\Controllers\Admin\V1\ProductReviewController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::get('products', 'products');
        Route::get('{review}', 'show')->whereNumber('review');
        Route::patch('{review}/status', 'status')->whereNumber('review')
            ->middleware('permission:review.moderate');
        Route::post('{review}/reply', 'reply')->whereNumber('review')
            ->middleware(['permission:review.reply', 'throttle:30,1']);
        Route::patch('{review}/reply', 'editReply')->whereNumber('review')
            ->middleware('permission:review.reply');
        Route::patch('{review}/reply/status', 'replyStatus')->whereNumber('review')
            ->middleware('permission:review.reply');
    });
