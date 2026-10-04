<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ReviewPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $names = ['review.view', 'review.moderate', 'review.reply'];
        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        foreach (['admin', 'staff'] as $name) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            // Chỉ bổ sung quyền đánh giá; giữ mọi quyền và tài khoản hiện tại.
            $role->givePermissionTo($names);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
