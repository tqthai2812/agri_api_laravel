<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            AdminRoleSeeder::class,

            CategorySeeder::class,
            SubcategorySeeder::class,
            OriginSeeder::class,

            ProductSeeder::class,
            ProductVariantSeeder::class,
            ProductPackageSeeder::class,
            ProductImageSeeder::class,
            DeliveryMethodSeeder::class,
            DiscountSeeder::class,
            OrderSeeder::class,
        ]);
    }
}
