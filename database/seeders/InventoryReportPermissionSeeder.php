<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\{Permission, Role};
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
        $admin = Role::where("name", "admin")
            ->where("guard_name", "web")
            ->first();
        if ($admin) {
            $admin->givePermissionTo($names);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
