<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ContactPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $names = ['contact.view', 'contact.update'];
        foreach ($names as $name) Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        foreach (['admin', 'staff'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'])->givePermissionTo($names);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
