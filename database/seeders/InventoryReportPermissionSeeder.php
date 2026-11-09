<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
class InventoryReportPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $names = ["report.inventory.view", "report.inventory.export"];
        foreach ($names as $name) {
            Permission::firstOrCreate(["name" => $name, "guard_name" => "web"]);
        }
        // Permission catalog only; reruns never re-grant revoked permissions.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}