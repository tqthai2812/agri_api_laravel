<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $guardName = 'web';

        $permissions = [
            // Dashboard
            'dashboard.view',

            // Category
            'category.view',
            'category.create',
            'category.update',
            'category.delete',

            // Subcategory
            'subcategory.view',
            'subcategory.create',
            'subcategory.update',
            'subcategory.delete',

            // Origin
            'origin.view',
            'origin.create',
            'origin.update',
            'origin.delete',

            // Product
            'product.view',
            'product.create',
            'product.update',
            'product.delete',

            // Order
            'order.view',
            'order.create',
            'order.update',
            'order.delete',

            // User
            'user.view',
            'user.create',
            'user.update',
            'user.delete',
            'user.assign-role',

            // Role
            'role.view',
            'role.create',
            'role.update',
            'role.delete',
            'role.assign-permission',

            // Permission
            'permission.view',

            // Other admin pages
            'inventory.view',
            'gallery.view',
            'article.view',
            'settings.view',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => $guardName,
            ]);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
