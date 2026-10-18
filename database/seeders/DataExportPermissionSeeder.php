<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DataExportPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (["export.view", "export.create"] as $name) {
            Permission::findOrCreate($name, "web");
        }
        foreach (["admin", "staff"] as $name) {
            $role = Role::where("name", $name)
                ->where("guard_name", "web")
                ->first();
            $role?->givePermissionTo(["export.view", "export.create"]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
