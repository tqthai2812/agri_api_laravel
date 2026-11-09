<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class DataExportPermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (["export.view", "export.create"] as $name) {
            Permission::findOrCreate($name, "web");
        }
        // Never re-grant a permission an administrator previously revoked.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}