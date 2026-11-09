<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $names = [
            "dashboard.view",
            "category.view",
            "category.create",
            "category.update",
            "category.delete",
            "subcategory.view",
            "subcategory.create",
            "subcategory.update",
            "subcategory.delete",
            "origin.view",
            "origin.create",
            "origin.update",
            "origin.delete",
            "product.view",
            "product.create",
            "product.update",
            "product.delete",
            "order.view",
            "order.create",
            "order.update",
            "order.delete",
            "user.view",
            "user.create",
            "user.update",
            "user.delete",
            "user.assign-role",
            "role.view",
            "role.create",
            "role.update",
            "role.delete",
            "role.assign-permission",
            "permission.view",
            "gallery.view",
            "article.view",
            "article.create",
            "article.update",
            "article.delete",
            "settings.view",
            "inventory.view",
            "inventory.create",
            "inventory.update",
            "inventory.delete",
            "delivery-method.view",
            "delivery-method.create",
            "delivery-method.update",
            "delivery-method.delete",
            "discount.view",
            "discount.create",
            "discount.update",
            "discount.delete",
            "supplier.view",
            "supplier.create",
            "supplier.update",
            "supplier.delete",
            "review.view",
            "review.moderate",
            "review.reply",
            "contact.view",
            "contact.update",
            "user.assign-permission",
            "export.view",
            "export.create",
            "expense.view",
            "expense.create",
            "expense.update",
            "expense.post",
            "expense.pay",
            "expense.reverse",
            "expense-category.manage",
            "report.profit.view",
            "report.profit.export",
            "report.inventory.view",
            "report.inventory.export",
        ];
        foreach ($names as $name) {
            Permission::findOrCreate($name, "web");
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}