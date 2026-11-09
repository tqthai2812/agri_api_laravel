<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AdminRoleSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::firstOrCreate([
            "name" => "admin",
            "guard_name" => "web",
        ]);
        $staffRole = Role::firstOrCreate([
            "name" => "staff",
            "guard_name" => "web",
        ]);
        Role::firstOrCreate(["name" => "customer", "guard_name" => "web"]);
        $staffPermissions = [
            "dashboard.view",
            "product.view",
            "product.create",
            "product.update",
            "category.view",
            "category.create",
            "category.update",
            "subcategory.view",
            "subcategory.create",
            "subcategory.update",
            "origin.view",
            "origin.create",
            "origin.update",
            "order.view",
            "order.update",
            "user.view",
            "inventory.view",
            "inventory.create",
            "inventory.update",
            "delivery-method.view",
            "delivery-method.create",
            "delivery-method.update",
            "discount.view",
            "discount.create",
            "discount.update",
            "supplier.view",
            "supplier.create",
            "supplier.update",
            "article.view",
            "article.create",
            "article.update",
            "article.delete",
            "review.view",
            "review.moderate",
            "review.reply",
            "contact.view",
            "contact.update",
            "export.view",
            "export.create",
        ];

        // Default grants only when the role is first created. Reruns preserve edits.
        if ($adminRole->wasRecentlyCreated) {
            $adminRole->syncPermissions(
                Permission::where("guard_name", "web")->get(),
            );
        }
        if ($staffRole->wasRecentlyCreated) {
            $staffRole->syncPermissions(
                Permission::where("guard_name", "web")
                    ->whereIn("name", $staffPermissions)
                    ->get(),
            );
        }
        // Existing accounts, passwords, statuses, roles and direct permissions stay untouched.
        // For a new installation use: php artisan access:bootstrap-admin EMAIL
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}