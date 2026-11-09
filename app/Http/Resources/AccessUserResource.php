<?php

namespace App\Http\Resources;

use App\Services\AccessControlService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Permission;

class AccessUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $user->loadMissing(["roles.permissions", "permissions"]);
        $admin = app(AccessControlService::class)->isAdmin($user);
        $roles = $user->roles->where("guard_name", "web")->values();
        $inherited = $roles
            ->flatMap(fn($role) => $role->permissions->pluck("name"))
            ->unique()
            ->sort()
            ->values();
        $direct = $user->permissions
            ->where("guard_name", "web")
            ->pluck("name")
            ->sort()
            ->values();
        if ($admin && !$request->attributes->has("access.permission_names")) {
            $request->attributes->set(
                "access.permission_names",
                Permission::where("guard_name", "web")
                    ->orderBy("name")
                    ->pluck("name"),
            );
        }
        $effective = $admin
            ? $request->attributes->get("access.permission_names")
            : $inherited->merge($direct)->unique()->sort()->values();
        // Explicit allowlist: never serialize legacy role, password or internal token columns.
        return [
            "id" => $user->id,
            "name" => $user->name,
            "email" => $user->email,
            "phone_number" => $user->phone_number,
            "avatar" => $user->avatar,
            "avatar_url" => $user->avatar
                ? asset("storage/" . ltrim($user->avatar, "/"))
                : null,
            "is_active" => (bool) $user->is_active,
            "email_verified_at" => $user->email_verified_at,
            "created_at" => $user->created_at,
            "updated_at" => $user->updated_at,
            "roles" => $roles->pluck("name"),
            "permissions" => $effective,
            "direct_permissions" => $direct,
            "inherited_permissions" => $inherited,
            "role_permissions" => $roles->map(
                fn($r) => [
                    "id" => $r->id,
                    "name" => $r->name,
                    "permissions" => $r->permissions
                        ->pluck("name")
                        ->sort()
                        ->values(),
                ],
            ),
            "is_super_admin" => $admin,
            "can_assign_roles" =>
                $request->user() &&
                !$request->user()->is($user) &&
                $request->user()->can("user.assign-role") &&
                app(AccessControlService::class)->canManageTarget(
                    $request->user(),
                    $user,
                ),
            "can_assign_permissions" =>
                $request->user() &&
                !$request->user()->is($user) &&
                $request->user()->can("user.assign-permission") &&
                app(AccessControlService::class)->canManageTarget(
                    $request->user(),
                    $user,
                ),
            "access_revision" => hash(
                "sha256",
                json_encode([
                    $roles->pluck("name")->sort()->values(),
                    $direct,
                    $inherited,
                ]),
            ),
        ];
    }
}