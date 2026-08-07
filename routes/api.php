<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Admin\V1\CategoryController;
use App\Http\Controllers\Admin\V1\OriginController;
use App\Http\Controllers\Admin\V1\PermissionController;
use App\Http\Controllers\Admin\V1\ProductController;
use App\Http\Controllers\Admin\V1\RoleController;
use App\Http\Controllers\Admin\V1\SubcategoryController;
use App\Http\Controllers\Admin\V1\UserController;
use App\Http\Controllers\Admin\V1\UserRoleController;
use App\Http\Controllers\Admin\V1\InventoryController;

/*
|--------------------------------------------------------------------------
| Authenticated user
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    $user = $request->user();

    return response()->json([
        'user' => array_merge($user->toArray(), [
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]),
    ]);
});

/*
|--------------------------------------------------------------------------
| Admin API V1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        /*
        |--------------------------------------------------------------------------
        | Categories / Subcategories / Origins
        |--------------------------------------------------------------------------
        */

        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('subcategories', SubcategoryController::class);
        Route::apiResource('origins', OriginController::class);

        /*
        |--------------------------------------------------------------------------
        | Products
        |--------------------------------------------------------------------------
        |
        | Route apiResource đã có:
        | GET    /api/v1/products
        | POST   /api/v1/products
        | GET    /api/v1/products/{product}
        | PUT    /api/v1/products/{product}
        | PATCH  /api/v1/products/{product}
        | DELETE /api/v1/products/{product}
        |
        | Khi update có upload ảnh bằng FormData, frontend nên gửi:
        | POST /api/v1/products/{id}
        | _method = PUT
        |
        */

        Route::apiResource('products', ProductController::class);

        /*
        |--------------------------------------------------------------------------
        | Roles / Permissions
        |--------------------------------------------------------------------------
        */

        Route::apiResource('roles', RoleController::class);

        Route::put('roles/{role}/permissions', [
            RoleController::class,
            'syncPermissions',
        ]);

        Route::get('permissions', [
            PermissionController::class,
            'index',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Users / Assign Roles
        |--------------------------------------------------------------------------
        */

        Route::put('users/{user}/roles', [UserRoleController::class, 'update']);

        Route::apiResource('users', UserController::class)->only([
            'index',
            'store',
            'show',
            'update',
            'destroy',
        ]);

        Route::get('inventory', [InventoryController::class, 'index']);
        Route::get('inventory/{package}', [InventoryController::class, 'show']);

        Route::get('inventory-transactions', [InventoryController::class, 'transactions']);
        Route::post('inventory-transactions', [InventoryController::class, 'store']);
        Route::put('inventory-transactions/{transaction}', [InventoryController::class, 'update']);
    });

require __DIR__ . '/auth.php';
