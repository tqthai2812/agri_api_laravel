<?php

use App\Http\Controllers\Admin\V1\CropController;
use App\Http\Controllers\Admin\V1\ProductPurposeController;
use App\Http\Controllers\Admin\V1\CropGrowthStageController;
use App\Http\Controllers\Admin\V1\ProductUsageGuideController;
use App\Http\Controllers\Client\V1\PublicFarmUtilityController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/farm-utilities')->middleware('auth:sanctum')->group(function (): void {
    Route::get('options/crops', [ProductUsageGuideController::class, 'crops']);
    Route::get('options/purposes', [ProductUsageGuideController::class, 'purposes']);
    Route::get('options/growth-stages', [ProductUsageGuideController::class, 'growthStages']);
    Route::apiResource('purposes', ProductPurposeController::class)->parameters(['purposes' => 'purpose']);
    Route::apiResource('growth-stages', CropGrowthStageController::class)->parameters(['growth-stages' => 'growthStage']);
    Route::get('options/variants', [ProductUsageGuideController::class, 'variants']);
    Route::apiResource('crops', CropController::class);
    Route::apiResource('usage-guides', ProductUsageGuideController::class)
        ->parameters(['usage-guides' => 'usageGuide']);
});

Route::prefix('v1/public/farm-utilities')->group(function (): void {
    Route::get('crops', [PublicFarmUtilityController::class, 'crops']);
    Route::get('usage-guides', [PublicFarmUtilityController::class, 'index']);
    Route::get('usage-guides/{guide}', [PublicFarmUtilityController::class, 'show'])->whereNumber('guide');
});