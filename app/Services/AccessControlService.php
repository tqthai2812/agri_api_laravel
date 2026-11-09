<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class AccessControlService
{
    public function isAdmin(User $user): bool
    {
        return $user->hasRole("admin", "web");
    }

    /** Every RBAC/user mutation takes the same lock before reading its actor/target. */
    public function write(User $actor, Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $operation) {
            $this->lock();
            $actor = User::query()->findOrFail($actor->getKey());
            abort_unless($actor->is_active, 403, "Tài khoản đã bị khóa.");
            $result = $operation($actor);
            DB::afterCommit(
                fn() => app(
                    PermissionRegistrar::class,
                )->forgetCachedPermissions(),
            );
            return $result;
        });
    }

    public function lock(): void
    {
        $admin = Role::query()
            ->where("name", "admin")
            ->where("guard_name", "web")
            ->lockForUpdate()
            ->first();
        abort_unless(
            $admin,
            409,
            "Thiếu vai trò admin. Hãy chạy AdminRoleSeeder trước.",
        );
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function require(User $actor, string $permission): void
    {
        Gate::forUser($actor)->authorize($permission);
    }

    public function isSensitive(string $name): bool
    {
        foreach (config("access_control.sensitive_prefixes", []) as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        return false;
    }

    public function assertGrantable(User $actor, array $permissions): void
    {
        // MySQL can compare names without case sensitivity; Spatie uses canonical codes.
        $known = Permission::where("guard_name", "web")
            ->whereIn("name", $permissions)
            ->pluck("name")
            ->all();
        foreach ($permissions as $name) {
            if (!in_array($name, $known, true)) {
                throw ValidationException::withMessages([
                    "permissions" =>
                        "Mã quyền không tồn tại hoặc sai chữ hoa/thường.",
                ]);
            }
        }
        if ($this->isAdmin($actor)) {
            return;
        }
        foreach (array_unique($permissions) as $permission) {
            abort_if(
                $this->isSensitive($permission) || !$actor->can($permission),
                403,
                "Bạn chỉ được cấp hoặc thu hồi các quyền nghiệp vụ mình đang có. Quyền quản trị chỉ Admin được cấp.",
            );
        }
    }

    public function isPrivileged(User $user): bool
    {
        return $this->isAdmin($user) ||
            $user
                ->getAllPermissions()
                ->contains(fn($p) => $this->isSensitive($p->name));
    }

    public function canManageTarget(User $actor, User $target): bool
    {
        if ($this->isAdmin($actor)) {
            return true;
        }
        return !$this->isPrivileged($target) &&
            $target
                ->getAllPermissions()
                ->every(fn($permission) => $actor->can($permission->name));
    }

    public function assertTarget(User $actor, User $target): void
    {
        abort_unless(
            $this->canManageTarget($actor, $target),
            403,
            "Bạn không được thay đổi tài khoản có quyền quản trị hoặc quyền vượt quá phạm vi của mình.",
        );
    }

    public function assertNotSelf(User $actor, User $target): void
    {
        abort_if(
            $actor->is($target),
            403,
            "Bạn không thể tự thay đổi vai trò hoặc quyền trực tiếp của mình.",
        );
    }

    public function assertRoleEditable(
        User $actor,
        Role $role,
        bool $rename = false,
    ): void {
        abort_unless($role->guard_name === "web", 404);
        abort_if(
            $role->name === "admin",
            403,
            "Vai trò admin có toàn quyền hệ thống và được bảo vệ.",
        );
        abort_if(
            $rename &&
                in_array(
                    $role->name,
                    config("access_control.reserved_roles"),
                    true,
                ),
            403,
            "Không thể đổi tên vai trò hệ thống.",
        );
        if (!$this->isAdmin($actor)) {
            abort_if(
                $actor->hasRole($role->name, "web"),
                403,
                "Bạn không thể sửa vai trò đang gán cho chính mình.",
            );
            $this->assertGrantable(
                $actor,
                $role->permissions()->pluck("name")->all(),
            );
        }
    }

    public function syncRolePermissions(
        User $actor,
        Role $role,
        array $permissions,
    ): void {
        $this->require($actor, "role.assign-permission");
        $this->assertRoleEditable($actor, $role);
        $this->assertGrantable($actor, $permissions);
        $role->syncPermissions($permissions);
    }

    public function rolesForAssignment(
        User $actor,
        array $names,
    ): \Illuminate\Database\Eloquent\Collection {
        $this->require($actor, "user.assign-role");
        $roles = Role::query()
            ->where("guard_name", "web")
            ->whereIn("name", $names)
            ->with("permissions")
            ->get();
        if ($roles->count() !== count(array_unique($names))) {
            throw ValidationException::withMessages([
                "roles" => "Có vai trò không tồn tại.",
            ]);
        }
        if (!$this->isAdmin($actor)) {
            foreach ($roles as $role) {
                abort_if(
                    $role->name === "admin",
                    403,
                    "Chỉ Admin được cấp vai trò admin.",
                );
                $this->assertGrantable(
                    $actor,
                    $role->permissions->pluck("name")->all(),
                );
            }
        }
        return $roles;
    }

    public function syncUserRoles(User $actor, User $target, array $names): void
    {
        $this->require($actor, "user.assign-role");
        $this->assertNotSelf($actor, $target);
        $this->assertTarget($actor, $target);
        $roles = $this->rolesForAssignment($actor, $names);
        if (!$this->isAdmin($actor)) {
            // Removing a powerful role is an access change too.
            $this->assertGrantable(
                $actor,
                $target->getPermissionsViaRoles()->pluck("name")->all(),
            );
        }
        if (
            $target->is_active &&
            $this->isAdmin($target) &&
            !in_array("admin", $names, true)
        ) {
            $this->assertAnotherActiveAdmin($target);
        }
        $target->syncRoles($roles);
    }

    public function syncUserPermissions(
        User $actor,
        User $target,
        array $permissions,
    ): void {
        $this->require($actor, "user.assign-permission");
        $this->assertNotSelf($actor, $target);
        $this->assertTarget($actor, $target);
        $this->assertGrantable(
            $actor,
            array_merge(
                $permissions,
                $target->permissions()->pluck("name")->all(),
            ),
        );
        $target->syncPermissions($permissions);
    }

    public function assertAnotherActiveAdmin(User $target): void
    {
        abort_unless(
            User::query()
                ->where("is_active", true)
                ->whereKeyNot($target->getKey())
                ->role("admin", "web")
                ->exists(),
            422,
            "Phải giữ ít nhất một Admin đang hoạt động.",
        );
    }

    public function assertUserUpdate(
        User $actor,
        User $target,
        array $data,
    ): void {
        $this->assertTarget($actor, $target);
        if (array_key_exists("is_active", $data) && !$data["is_active"]) {
            abort_if(
                $actor->is($target),
                403,
                "Bạn không thể tự khóa tài khoản.",
            );
            if ($target->is_active && $this->isAdmin($target)) {
                $this->assertAnotherActiveAdmin($target);
            }
        }
    }

    public function assertUserDelete(User $actor, User $target): void
    {
        abort_if($actor->is($target), 403, "Bạn không thể tự xóa tài khoản.");
        $this->assertTarget($actor, $target);
        if ($target->is_active && $this->isAdmin($target)) {
            $this->assertAnotherActiveAdmin($target);
        }
    }
}