<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class AdminRoleSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $guardName = 'web';

        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => $guardName,
        ]);

        $adminRole->syncPermissions(
            Permission::where('guard_name', $guardName)->pluck('name')->toArray()
        );

        $admin = User::where('email', 'thai@gmail.com')->first();

        if ($admin) {
            $admin->syncRoles(['admin']);

            // Nếu bảng users của bạn còn cột role thì cập nhật luôn
            $admin->update([
                'role' => 'admin',
            ]);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
