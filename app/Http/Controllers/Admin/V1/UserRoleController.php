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

class UserRoleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware("permission:user.assign-role")];
    }

    public function update(
        Request $request,
        User $user,
        AccessControlService $access,
    ): JsonResponse {
        $data = $request->validate([
            "roles" => ["present", "array", "max:100"],
            "roles.*" => [
                "required",
                "string",
                "distinct",
                Rule::exists("roles", "name")->where("guard_name", "web"),
            ],
        ]);
        $target = $access->write($request->user(), function (User $actor) use (
            $access,
            $user,
            $data,
        ) {
            $target = User::lockForUpdate()->findOrFail($user->id);
            $access->syncUserRoles($actor, $target, $data["roles"]);
            return $target->fresh();
        });
        return response()->json([
            "message" => "Đã cập nhật vai trò.",
            "data" => [
                "user" => (new AccessUserResource($target))->resolve($request),
            ],
        ]);
    }
}