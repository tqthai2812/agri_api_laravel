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
use App\Http\Controllers\Admin\V1\DeliveryMethodController;
use App\Http\Controllers\Admin\V1\DiscountController;
use App\Http\Controllers\Admin\V1\OrderController;
use App\Http\Controllers\Admin\V1\NewsController;

use App\Http\Controllers\Client\V1\CartController;
use App\Http\Controllers\Client\V1\CheckoutController;
use App\Http\Controllers\Client\V1\PublicCategoryController;
use App\Http\Controllers\Client\V1\PublicProductController;
use App\Http\Controllers\Client\V1\PublicNewsController;
use App\Http\Controllers\Client\V1\PublicProductFilterController;
use App\Http\Controllers\Client\V1\ShippingAddressController;
use App\Http\Controllers\Client\V1\ProfileController;
use App\Http\Controllers\Client\V1\OrderController as ClientOrderController;
use App\Http\Controllers\Client\V1\WishlistController;
use App\Http\Controllers\Client\V1\LocationController;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    $user = $request->user();

    return response()->json([
        'user' => array_merge($user->toArray(), [
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]),
    ]);
});

Route::prefix('v1')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('subcategories', SubcategoryController::class);
        Route::apiResource('origins', OriginController::class);
        Route::apiResource('products', ProductController::class);

        Route::apiResource('roles', RoleController::class);
        Route::put('roles/{role}/permissions', [
            RoleController::class,
            'syncPermissions',
        ]);
        Route::get('permissions', [PermissionController::class, 'index']);

        Route::put('users/{user}/roles', [UserRoleController::class, 'update']);
        Route::apiResource('users', UserController::class)->only([
            'index',
            'store',
            'show',
            'update',
            'destroy',
        ]);

        Route::apiResource('discounts', DiscountController::class);
        Route::apiResource('delivery-methods', DeliveryMethodController::class);

        // Kho và lô
        Route::get('inventory', [InventoryController::class, 'index']);

        Route::get('inventory/{package}/lots', [
            InventoryController::class,
            'lots',
        ])->whereNumber('package');

        Route::post('inventory/{package}/initialize-lots', [
            InventoryController::class,
            'initializeLots',
        ])->whereNumber('package');

        Route::patch('inventory/{package}/lots/{lot}', [
            InventoryController::class,
            'updateLot',
        ])->whereNumber(['package', 'lot']);

        Route::get('inventory/{package}', [
            InventoryController::class,
            'show',
        ])->whereNumber('package');

        Route::get('inventory-transactions', [
            InventoryController::class,
            'transactions',
        ]);

        Route::post('inventory-transactions', [
            InventoryController::class,
            'store',
        ]);

        Route::put('inventory-transactions/{transaction}', [
            InventoryController::class,
            'update',
        ])->whereNumber('transaction');

        Route::get('orders/status-counts', [OrderController::class, 'statusCounts']);
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus']);

        Route::get('cart', [CartController::class, 'show']);
        Route::post('cart/items', [CartController::class, 'store']);
        Route::put('cart/items/{item}', [CartController::class, 'update']);
        Route::delete('cart/items/{item}', [CartController::class, 'destroy']);
        Route::delete('cart', [CartController::class, 'clear']);

        Route::get('wishlist', [WishlistController::class, 'index']);
        Route::post('wishlist/items', [WishlistController::class, 'store']);
        Route::post('wishlist/toggle', [WishlistController::class, 'toggle']);
        Route::delete('wishlist/items', [WishlistController::class, 'destroyMany']);
        Route::delete('wishlist/items/{wishlist}', [WishlistController::class, 'destroy']);

        Route::get('profile', [ProfileController::class, 'show']);
        Route::post('profile', [ProfileController::class, 'update']);
        Route::put('profile/password', [ProfileController::class, 'changePassword']);

        Route::get('my-orders/status-counts', [ClientOrderController::class, 'statusCounts']);
        Route::get('my-orders', [ClientOrderController::class, 'index']);
        Route::get('my-orders/{order}', [ClientOrderController::class, 'show']);
        Route::patch('my-orders/{order}/cancel', [ClientOrderController::class, 'cancel']);

        Route::get('shipping-addresses', [ShippingAddressController::class, 'index']);
        Route::post('shipping-addresses', [ShippingAddressController::class, 'store']);
        Route::put('shipping-addresses/{address}', [ShippingAddressController::class, 'update']);
        Route::delete('shipping-addresses/{address}', [ShippingAddressController::class, 'destroy']);
        Route::patch('shipping-addresses/{address}/default', [ShippingAddressController::class, 'setDefault']);

        Route::get('checkout/options', [CheckoutController::class, 'options']);
        Route::post('checkout/preview', [CheckoutController::class, 'preview']);
        Route::post('checkout', [CheckoutController::class, 'checkout']);

        Route::get('news/status-counts', [NewsController::class, 'statusCounts']);
        Route::apiResource('news', NewsController::class)->except(['update']);
        Route::post('news/{news}', [NewsController::class, 'update']);

        Route::get(
            'locations/provinces',
            [LocationController::class, 'provinces']
        );

        Route::get(
            'locations/provinces/{province}/wards',
            [LocationController::class, 'wards']
        )->whereNumber('province');

        Route::get(
            'locations/shipping-regions',
            [LocationController::class, 'shippingRegions']
        );
    });

Route::prefix('v1/public')->group(function () {
    Route::get('categories', [PublicCategoryController::class, 'index']);
    Route::get('product-filters', [PublicProductFilterController::class, 'index']);

    Route::get('products', [PublicProductController::class, 'index']);
    Route::get('products/{product}', [PublicProductController::class, 'show']);

    Route::get('news', [PublicNewsController::class, 'index']);
    Route::get('news/{slug}', [PublicNewsController::class, 'show']);
});

require __DIR__ . '/auth.php';
