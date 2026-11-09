<?php

namespace App\Http\Controllers\Admin\V1;

use App\Contracts\Services\ImageUploadServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\User\StoreUserRequest;
use App\Http\Requests\Admin\User\UpdateUserRequest;
use App\Http\Resources\AccessUserResource;
use App\Models\User;
use App\Services\AccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Throwable;

class UserController extends Controller implements HasMiddleware
{
    public function __construct(
        protected ImageUploadServiceInterface $imageUploadService,
        private AccessControlService $access,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware("permission:user.view", only: ["index", "show"]),
            new Middleware("permission:user.create", only: ["store"]),
            new Middleware("permission:user.update", only: ["update"]),
            new Middleware("permission:user.delete", only: ["destroy"]),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            "search" => ["nullable", "string", "max:255"],
            "role" => ["nullable", "string", "max:100"],
            "is_active" => ["nullable", "in:0,1,true,false"],
            "page" => ["sometimes", "integer", "min:1"],
            "per_page" => ["sometimes", "integer", "min:1", "max:100"],
        ]);
        $query = User::query()
            ->select([
                "id",
                "name",
                "email",
                "phone_number",
                "avatar",
                "is_active",
                "email_verified_at",
                "created_at",
                "updated_at",
            ])
            ->with(["roles.permissions", "permissions"]);
        if (!empty($data["search"])) {
            $search = "%" . $data["search"] . "%";
            $query->where(
                fn($q) => $q
                    ->where("name", "like", $search)
                    ->orWhere("email", "like", $search)
                    ->orWhere("phone_number", "like", $search),
            );
        }
        if ($request->filled("is_active")) {
            $query->where("is_active", $request->boolean("is_active"));
        }
        if (!empty($data["role"])) {
            $query->whereHas(
                "roles",
                fn($q) => $q
                    ->where("name", $data["role"])
                    ->where("guard_name", "web"),
            );
        }
        $users = $query->orderByDesc("id")->paginate($data["per_page"] ?? 15);
        return response()->json([
            "data" => $users
                ->getCollection()
                ->map(
                    fn($u) => (new AccessUserResource($u))->resolve($request),
                ),
            "meta" => [
                "current_page" => $users->currentPage(),
                "last_page" => $users->lastPage(),
                "per_page" => $users->perPage(),
                "total" => $users->total(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $newAvatar = null;
        try {
            $user = $this->access->write($request->user(), function (
                User $actor,
            ) use ($data, $request, &$newAvatar) {
                $this->access->require($actor, "user.create");
                $attributes = $data;
                unset($attributes["roles"], $attributes["avatar"]);
                $attributes["is_active"] = $attributes["is_active"] ?? true;
                if (array_key_exists("roles", $data)) {
                    $roles = $this->access->rolesForAssignment(
                        $actor,
                        $data["roles"],
                    );
                } else {
                    // user.create may create a normal customer without role-assignment authority.
                    $customer = Role::where("name", "customer")
                        ->where("guard_name", "web")
                        ->first();
                    abort_unless(
                        $customer,
                        422,
                        "Thiếu vai trò customer. Hãy chạy AdminRoleSeeder.",
                    );
                    $roles = collect([$customer]);
                }
                // All authorization has passed before model events or file operations can run.
                $user = User::create($attributes);
                $user->syncRoles($roles);
                if ($request->hasFile("avatar")) {
                    $newAvatar = $this->imageUploadService->upload(
                        $request->file("avatar"),
                        "avatars",
                    );
                    $user->update(["avatar" => $newAvatar]);
                }
                return $user;
            });
        } catch (Throwable $error) {
            $this->removeImage($newAvatar);
            throw $error; // Preserve framework 403/422; production APP_DEBUG=false hides unexpected errors.
        }
        return response()->json(
            [
                "message" => "Đã thêm người dùng.",
                "data" => (new AccessUserResource($user->fresh()))->resolve(
                    $request,
                ),
            ],
            201,
        );
    }

    public function show(Request $request, User $user): JsonResponse
    {
        return response()->json([
            "data" => (new AccessUserResource($user))->resolve($request),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();
        $newAvatar = null;
        try {
            $target = $this->access->write($request->user(), function (
                User $actor,
            ) use ($data, $request, $user, &$newAvatar) {
                $this->access->require($actor, "user.update");
                $target = User::lockForUpdate()->findOrFail($user->id);
                $this->access->assertUserUpdate($actor, $target, $data);
                if (array_key_exists("roles", $data)) {
                    $this->access->syncUserRoles(
                        $actor,
                        $target,
                        $data["roles"],
                    );
                }
                $attributes = $data;
                unset($attributes["roles"], $attributes["avatar"]);
                if (empty($attributes["password"])) {
                    unset($attributes["password"]);
                }
                if ($request->hasFile("avatar")) {
                    $oldAvatar = $target->avatar;
                    $newAvatar = $this->imageUploadService->upload(
                        $request->file("avatar"),
                        "avatars",
                    );
                    $attributes["avatar"] = $newAvatar;
                    DB::afterCommit(fn() => $this->removeImage($oldAvatar));
                }
                $target->update($attributes);
                return $target->fresh();
            });
        } catch (Throwable $error) {
            $this->removeImage($newAvatar);
            throw $error;
        }
        return response()->json([
            "message" => "Đã cập nhật người dùng.",
            "data" => (new AccessUserResource($target))->resolve($request),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->access->write($request->user(), function (User $actor) use (
            $user,
        ) {
            $this->access->require($actor, "user.delete");
            $target = User::lockForUpdate()->findOrFail($user->id);
            $this->access->assertUserDelete($actor, $target);
            $avatar = $target->avatar;
            $target->delete();
            DB::afterCommit(fn() => $this->removeImage($avatar));
        });
        return response()->json(["message" => "Đã xóa người dùng."]);
    }

    private function removeImage(?string $path): void
    {
        if (!$path) {
            return;
        }
        try {
            $this->imageUploadService->delete($path);
        } catch (Throwable $error) {
            report($error);
        }
    }
}