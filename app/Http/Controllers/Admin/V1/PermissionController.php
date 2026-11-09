<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Services\AccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(
                "permission:permission.view|role.assign-permission|user.assign-permission",
            ),
        ];
    }

    public function index(
        Request $request,
        AccessControlService $access,
    ): JsonResponse {
        $groups = config("access_control.groups");
        $actions = config("access_control.actions");
        $permissions = Permission::where("guard_name", "web")
            ->with("roles:id,name,guard_name")
            ->orderBy("name")
            ->get();
        $data = $permissions
            ->map(function ($permission) use (
                $groups,
                $actions,
                $request,
                $access,
            ) {
                $group = Str::before($permission->name, ".");
                $action = Str::afterLast($permission->name, ".");
                $sensitive = $access->isSensitive($permission->name);
                $module = $groups[$group] ?? $group;
                $label =
                    config("access_control.labels")[$permission->name] ??
                    ($actions[$action] ?? $action) . " " . $module;
                return [
                    "id" => $permission->id,
                    "name" => $permission->name,
                    "label" => $label,
                    "group" => $group,
                    "group_label" => $module,
                    "description" =>
                        config("access_control.descriptions")[
                            $permission->name
                        ] ??
                        (null ??
                            $label .
                                "." .
                                ($sensitive
                                    ? " Chỉ Admin được cấp quyền này."
                                    : "")),
                    "sensitive" => $sensitive,
                    "grantable" =>
                        $access->isAdmin($request->user()) ||
                        (!$sensitive &&
                            $request->user()->can($permission->name)),
                    "roles" => $permission->roles
                        ->where("guard_name", "web")
                        ->values()
                        ->map(fn($r) => ["id" => $r->id, "name" => $r->name]),
                ];
            })
            ->groupBy("group");
        // Keep the existing grouped data shape; add UI metadata within each item.
        return response()->json(["data" => $data]);
    }
}