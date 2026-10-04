<?php

use App\Http\Controllers\Client\V1\ContactController as ClientContactController;
use App\Http\Controllers\Admin\V1\ContactController as AdminContactController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::get('my-contacts', [ClientContactController::class, 'index']);
    Route::get('my-contacts/{contact}', [ClientContactController::class, 'show'])->whereNumber('contact');
    Route::post('my-contacts', [ClientContactController::class, 'store'])->middleware('throttle:5,1');
    Route::middleware('permission:contact.view')->group(function () {
        Route::get('contacts', [AdminContactController::class, 'index']);
        Route::get('contacts/{contact}', [AdminContactController::class, 'show'])->whereNumber('contact');
        Route::patch('contacts/{contact}', [AdminContactController::class, 'update'])->whereNumber('contact')
            ->middleware('permission:contact.update');
    });
});
