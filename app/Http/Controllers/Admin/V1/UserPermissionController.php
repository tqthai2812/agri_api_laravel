<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccessUserResource;
use App\Models\User;
use App\Services\AccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class UserPermissionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware("permission:user.assign-permission")];
    }

    public function update(
        Request $request,
        User $user,
        AccessControlService $access,
    ): JsonResponse {
        $data = $request->validate([
            "permissions" => ["present", "array", "max:500"],
            "permissions.*" => [
                "required",
                "string",
                "distinct",
                Rule::exists("permissions", "name")->where("guard_name", "web"),
            ],
        ]);
        $target = $access->write($request->user(), function (User $actor) use (
            $user,
            $data,
            $access,
        ) {
            $target = User::query()->lockForUpdate()->findOrFail($user->id);
            $access->syncUserPermissions($actor, $target, $data["permissions"]);
            return $target->fresh();
        });
        return response()->json([
            "message" => "Đã cập nhật quyền trực tiếp.",
            "data" => [
                "user" => (new AccessUserResource($target))->resolve($request),
            ],
        ]);
    }
}