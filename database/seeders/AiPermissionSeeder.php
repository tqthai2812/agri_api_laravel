<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\{Permission, Role};
use Spatie\Permission\PermissionRegistrar;

class AiPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (["ai-knowledge.view", "ai-knowledge.reindex"] as $name) {
            $p = Permission::findOrCreate($name, "web");
            if (
                $admin = Role::where("name", "admin")
                    ->where("guard_name", "web")
                    ->first()
            ) {
                $admin->givePermissionTo($p);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}