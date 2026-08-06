<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\V1\RoleController;
use App\Http\Controllers\Admin\V1\PermissionController;
use App\Http\Controllers\Admin\V1\UserRoleController;
use App\Http\Controllers\Admin\V1\ProductController;
use App\Http\Controllers\Admin\V1\CategoryController;
use App\Http\Controllers\Admin\V1\SubcategoryController;
use App\Http\Controllers\Admin\V1\OriginController;
use App\Http\Controllers\Admin\V1\UserController;


Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    $user = $request->user();

    return response()->json([
        'user' => array_merge($user->toArray(), [
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]),
    ]);
});

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('subcategories', SubcategoryController::class);
    Route::apiResource('origins', OriginController::class);
    Route::apiResource('products', ProductController::class);
    Route::apiResource('roles', RoleController::class);
    Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions']);
    Route::get('permissions', [PermissionController::class, 'index']);
    Route::put('users/{user}/roles', [UserRoleController::class, 'update']);
    Route::apiResource('users', UserController::class)->only(['index', 'show']);
});

require __DIR__ . '/auth.php';
