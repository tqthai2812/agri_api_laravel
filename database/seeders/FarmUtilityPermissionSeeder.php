<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class FarmUtilityPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = [];
        foreach (['crop', 'usage-guide'] as $group) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                $permissions[] = Permission::firstOrCreate([
                    'name' => $group.'.'.$action, 'guard_name' => 'web',
                ]);
            }
        }
        // Chỉ bổ sung quyền mới, không sync/thu hồi hay cấp lại quyền cũ.
        $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();
        if ($admin) {
            $admin->givePermissionTo($permissions);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}