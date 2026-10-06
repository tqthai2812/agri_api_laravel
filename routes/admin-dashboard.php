<?php

use App\Http\Controllers\Admin\V1\DashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'permission:dashboard.view'])
    ->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index'])
            ->name('admin.dashboard.overview');
    });
