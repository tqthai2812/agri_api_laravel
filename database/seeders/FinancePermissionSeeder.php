<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\{Permission, Role};
use Spatie\Permission\PermissionRegistrar;

class FinancePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $names = [
            "expense.view",
            "expense.create",
            "expense.update",
            "expense.post",
            "expense.pay",
            "expense.reverse",
            "expense-category.manage",
            "report.profit.view",
            "report.profit.export",
        ];
        foreach ($names as $name) {
            Permission::firstOrCreate(["name" => $name, "guard_name" => "web"]);
        }
        // Add only new privileges to admin. Keep staff/customers and user records unchanged.
        $admin = Role::where("name", "admin")
            ->where("guard_name", "web")
            ->first();
        if ($admin) {
            $admin->givePermissionTo($names);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
