<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
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
        // Permission catalog only; reruns never re-grant revoked permissions.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}