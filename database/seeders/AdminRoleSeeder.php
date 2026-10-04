<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AdminRoleSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $guardName = 'web';
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => $guardName]);
        $staffRole = Role::firstOrCreate(['name' => 'staff', 'guard_name' => $guardName]);
        $customerRole = Role::firstOrCreate(['name' => 'customer', 'guard_name' => $guardName]);
        $adminRole->syncPermissions(Permission::where('guard_name', $guardName)->pluck('name')->all());
        $staffPermissions = [
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
            'delivery-method.view',
            'delivery-method.create',
            'delivery-method.update',
            'discount.view',
            'discount.create',
            'discount.update',
            'supplier.view',
            'supplier.create',
            'supplier.update',
            'article.view',
            'article.create',
            'article.update',
            'article.delete',
            'review.view',
            'review.moderate',
            'review.reply',
            'contact.view',
            'contact.update',
        ];
        $staffRole->syncPermissions($staffPermissions);
        $customerRole->syncPermissions([]);
        $accounts = [
            ['email' => 'thai@gmail.com', 'name' => 'Quản trị viên Agri', 'phone_number' => '0900000000', 'role' => 'admin'],
            ['email' => 'staff@gmail.com', 'name' => 'Nhân viên cửa hàng', 'phone_number' => '0911111111', 'role' => 'staff'],
            ['email' => 'customer@gmail.com', 'name' => 'Nguyễn Minh Anh', 'phone_number' => '0922222222', 'role' => 'customer'],
            ['email' => 'nguyen.van.nam@gmail.com', 'name' => 'Nguyễn Văn Nam', 'phone_number' => '0933333333', 'role' => 'customer'],
            ['email' => 'tran.thi.lan@gmail.com', 'name' => 'Trần Thị Lan', 'phone_number' => '0944444444', 'role' => 'customer'],
        ];
        foreach ($accounts as $account) {
            $user = User::firstOrCreate(['email' => $account['email']], [
                'name' => $account['name'],
                'password' => Hash::make('12345678'),
                'phone_number' => $account['phone_number'],
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $user->forceFill([
                'name' => $account['name'],
                'phone_number' => $account['phone_number'],
                'is_active' => true,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
            $user->syncRoles([$account['role']]);
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
