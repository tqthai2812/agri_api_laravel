<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Users, roles và permissions
            PermissionSeeder::class,
            AdminRoleSeeder::class,

            // Danh mục và dữ liệu nền sản phẩm
            CategorySeeder::class,
            SubcategorySeeder::class,
            OriginSeeder::class,
            SupplierSeeder::class,

            // Sản phẩm và quan hệ danh mục
            ProductSeeder::class,
            ProductVariantSeeder::class,
            ProductPackageSeeder::class,
            ProductImageSeeder::class,

            // Tags và nhà cung cấp sản phẩm
            TagSeeder::class,
            ProductTagSeeder::class,
            ProductSupplierSeeder::class,

            // Kho phải có trước giỏ hàng và đơn hàng
            InventorySeeder::class,

            // Giao hàng, giảm giá, địa chỉ và giỏ
            DeliveryMethodSeeder::class,
            DiscountSeeder::class,
            ShippingAddressSeeder::class,
            ShoppingCartSeeder::class,
            CartItemSeeder::class,

            // OrderSeeder tạo đồng bộ orders, order_addresses,
            // order_items, order_histories, payments,
            // stock_reservations và dữ liệu xuất kho.
            OrderSeeder::class,

            // Nội dung, đánh giá và tương tác khách hàng
            ProductReviewSeeder::class,
            WishlistSeeder::class,
            NewsSeeder::class,
            NewsImageSeeder::class,
            NewsTagSeeder::class,
            NewsCommentSeeder::class,
            ContactSeeder::class,
        ]);
    }
}
