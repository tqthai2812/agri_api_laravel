<?php

use App\Http\Controllers\Admin\V1\InventoryDocumentController;
use App\Http\Controllers\Admin\V1\SupplierController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function () {
        Route::apiResource('suppliers', SupplierController::class);

        Route::get('inventory-documents', [
            InventoryDocumentController::class,
            'index',
        ]);

        Route::post('inventory-documents', [
            InventoryDocumentController::class,
            'store',
        ]);

        Route::get('inventory-documents/{document}', [
            InventoryDocumentController::class,
            'show',
        ])->whereNumber('document');

        Route::put('inventory-documents/{document}', [
            InventoryDocumentController::class,
            'update',
        ])->whereNumber('document');

        Route::post('inventory-documents/{document}/post', [
            InventoryDocumentController::class,
            'post',
        ])->whereNumber('document');

        Route::patch('inventory-documents/{document}/cancel', [
            InventoryDocumentController::class,
            'cancel',
        ])->whereNumber('document');
    });
