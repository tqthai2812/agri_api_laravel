<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use App\Contracts\Services\ImageUploadServiceInterface;
use App\Services\ImageUploadService;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Repositories\ProductRepository;
use App\Contracts\Services\ProductServiceInterface;
use App\Services\ProductService;
use App\Contracts\Repositories\InventoryRepositoryInterface;
use App\Repositories\InventoryRepository;
use App\Contracts\Services\InventoryServiceInterface;
use App\Services\InventoryService;
use App\Contracts\Repositories\DeliveryMethodRepositoryInterface;
use App\Contracts\Services\DeliveryMethodServiceInterface;
use App\Repositories\DeliveryMethodRepository;
use App\Services\DeliveryMethodService;
use App\Contracts\Repositories\DiscountRepositoryInterface;
use App\Contracts\Services\DiscountServiceInterface;
use App\Repositories\DiscountRepository;
use App\Services\DiscountService;
use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Services\OrderServiceInterface;
use App\Repositories\OrderRepository;
use App\Services\OrderService;
use App\Contracts\Repositories\CartRepositoryInterface;
use App\Contracts\Services\CartServiceInterface;
use App\Contracts\Services\CheckoutServiceInterface;
use App\Repositories\CartRepository;
use App\Services\CartService;
use App\Services\CheckoutService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ProductRepositoryInterface::class, ProductRepository::class);
        $this->app->bind(ProductServiceInterface::class, ProductService::class);
        $this->app->bind(ImageUploadServiceInterface::class, ImageUploadService::class);
        $this->app->bind(InventoryRepositoryInterface::class, InventoryRepository::class);
        $this->app->bind(InventoryServiceInterface::class, InventoryService::class);
        $this->app->bind(DeliveryMethodRepositoryInterface::class, DeliveryMethodRepository::class);
        $this->app->bind(DeliveryMethodServiceInterface::class, DeliveryMethodService::class);
        $this->app->bind(DiscountRepositoryInterface::class, DiscountRepository::class);
        $this->app->bind(DiscountServiceInterface::class, DiscountService::class);
        $this->app->bind(OrderRepositoryInterface::class, OrderRepository::class);
        $this->app->bind(OrderServiceInterface::class, OrderService::class);
        $this->app->bind(CartRepositoryInterface::class, CartRepository::class);
        $this->app->bind(CartServiceInterface::class, CartService::class);
        $this->app->bind(CheckoutServiceInterface::class, CheckoutService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url') . "/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });
    }
}
