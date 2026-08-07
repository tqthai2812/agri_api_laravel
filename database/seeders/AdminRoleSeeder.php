<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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

        $staffRole = Role::firstOrCreate([
            'name' => 'staff',
            'guard_name' => $guardName,
        ]);

        $customerRole = Role::firstOrCreate([
            'name' => 'customer',
            'guard_name' => $guardName,
        ]);

        $adminRole->syncPermissions(
            Permission::where('guard_name', $guardName)->pluck('name')->toArray()
        );

        $staffRole->syncPermissions([
            'dashboard.view',

            'product.view',
            'product.create',
            'product.update',

            'category.view',
            'category.create',
            'category.update',

            'subcategory.view',
            'subcategory.create',
            'subcategory.update',

            'origin.view',
            'origin.create',
            'origin.update',

            'order.view',
            'order.update',

            'user.view',

            'inventory.view',
            'inventory.create',
            'inventory.update',
        ]);

        $customerRole->syncPermissions([]);

        $admin = User::where('email', 'thai@gmail.com')->first();

        if (!$admin) {
            $admin = User::create([
                'name' => 'Admin EVDesign',
                'email' => 'thai@gmail.com',
                'password' => Hash::make('12345678'),
                'phone_number' => '0900000000',
                'role' => 'admin',
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
        } else {
            $admin->update([
                'role' => 'admin',
                'is_active' => true,
                'email_verified_at' => $admin->email_verified_at ?? now(),
            ]);
        }

        $admin->syncRoles(['admin']);

        $staff = User::firstOrCreate(
            ['email' => 'staff@gmail.com'],
            [
                'name' => 'Nhân viên EVDesign',
                'password' => Hash::make('12345678'),
                'phone_number' => '0911111111',
                'role' => 'staff',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        if (Schema::hasColumn('users', 'role')) {
            $staff->update(['role' => 'staff']);
        }

        $staff->syncRoles(['staff']);

        $customer = User::firstOrCreate(
            ['email' => 'customer@gmail.com'],
            [
                'name' => 'Khách hàng mẫu',
                'password' => Hash::make('12345678'),
                'phone_number' => '0922222222',
                'role' => 'customer',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        if (Schema::hasColumn('users', 'role')) {
            $customer->update(['role' => 'customer']);
        }

        $customer->syncRoles(['customer']);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
