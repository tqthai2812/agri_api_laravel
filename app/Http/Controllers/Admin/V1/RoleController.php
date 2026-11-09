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
use Spatie\Permission\Models\Role;

class RoleController extends Controller implements HasMiddleware
{
    public function __construct(private AccessControlService $access) {}

    public static function middleware(): array
    {
        return [
            new Middleware(
                "permission:role.view|user.assign-role|user.view",
                only: ["index"],
            ),
            new Middleware("permission:role.view", only: ["show"]),
            new Middleware("permission:role.create", only: ["store"]),
            new Middleware(
                "permission:role.update",
                only: ["update", "syncPermissions"],
            ),
            new Middleware("permission:role.delete", only: ["destroy"]),
            new Middleware(
                "permission:role.assign-permission",
                only: ["syncPermissions"],
            ),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        if ($request->boolean("options")) {
            $roles = Role::where("guard_name", "web")
                ->with("permissions")
                ->orderBy("name")
                ->get();
            return response()->json([
                "data" => $roles->map(
                    fn($r) => [
                        "id" => $r->id,
                        "name" => $r->name,
                        "assignable" =>
                            $this->access->isAdmin($request->user()) ||
                            ($r->name !== "admin" &&
                                $r->permissions->every(
                                    fn($p) => !$this->access->isSensitive(
                                        $p->name,
                                    ) && $request->user()->can($p->name),
                                )),
                    ],
                ),
            ]);
        }
        $this->access->require($request->user(), "role.view");
        $data = $request->validate([
            "search" => ["nullable", "string", "max:255"],
            "type" => ["nullable", "in:system,custom"],
            "page" => ["sometimes", "integer", "min:1"],
            "per_page" => ["sometimes", "integer", "min:1", "max:100"],
        ]);
        // Explicit model guard: Sanctum may change auth.defaults.guard during this request.
        $query = (new Role(["guard_name" => "web"]))
            ->newQuery()
            ->where("guard_name", "web")
            ->with("permissions:id,name,guard_name")
            ->withCount("users")
            ->orderBy("name");
        if (!empty($data["search"])) {
            $query->where("name", "like", "%" . $data["search"] . "%");
        }
        if (($data["type"] ?? null) === "system") {
            $query->whereIn("name", config("access_control.reserved_roles"));
        }
        if (($data["type"] ?? null) === "custom") {
            $query->whereNotIn("name", config("access_control.reserved_roles"));
        }
        // Preserve the old array response; pagination is opt-in for the new UI.
        if (!$request->has("per_page")) {
            return response()->json(["data" => $query->get()]);
        }
        $roles = $query->paginate($data["per_page"]);
        return response()->json([
            "data" => $roles->items(),
            "meta" => [
                "current_page" => $roles->currentPage(),
                "last_page" => $roles->lastPage(),
                "per_page" => $roles->perPage(),
                "total" => $roles->total(),
            ],
        ]);
    }

    private function rules(?Role $role = null): array
    {
        return [
            "name" => [
                $role ? "sometimes" : "required",
                "required",
                "string",
                "max:100",
                'regex:/^[\pL\pN][\pL\pN _.-]*$/u',
                Rule::unique("roles", "name")
                    ->where("guard_name", "web")
                    ->ignore($role?->id),
            ],
            "permissions" => ["sometimes", "array", "max:500"],
            "permissions.*" => [
                "required",
                "string",
                "distinct",
                Rule::exists("permissions", "name")->where("guard_name", "web"),
            ],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        if (is_string($request->input("name"))) {
            $request->merge(["name" => trim($request->input("name"))]);
        }
        $data = $request->validate($this->rules(), [
            "name.unique" => "Tên vai trò đã tồn tại.",
        ]);
        $data["name"] = trim($data["name"]);
        $role = $this->access->write($request->user(), function (
            User $actor,
        ) use ($data) {
            $this->access->require($actor, "role.create");
            abort_if(
                in_array(
                    strtolower($data["name"]),
                    config("access_control.reserved_roles"),
                    true,
                ),
                403,
                "Tên vai trò hệ thống được bảo vệ.",
            );
            if (!empty($data["permissions"])) {
                $this->access->require($actor, "role.assign-permission");
                $this->access->assertGrantable($actor, $data["permissions"]);
            }
            $role = Role::create([
                "name" => $data["name"],
                "guard_name" => "web",
            ]);
            if (!empty($data["permissions"])) {
                $role->syncPermissions($data["permissions"]);
            }
            return $role;
        });
        return response()->json(
            [
                "message" => "Đã tạo vai trò.",
                "data" => $role->load("permissions")->loadCount("users"),
            ],
            201,
        );
    }

    public function show(Request $request, Role $role): JsonResponse
    {
        abort_unless($role->guard_name === "web", 404);
        $data = ["data" => $role->load("permissions")->loadCount("users")];
        if (
            $request->boolean("members") &&
            $request->user()->can("user.view")
        ) {
            $validated = $request->validate([
                "members_page" => ["sometimes", "integer", "min:1"],
            ]);
            $members = User::role($role)
                ->select(["id", "name", "email", "avatar", "is_active"])
                ->orderBy("id")
                ->paginate(
                    10,
                    ["*"],
                    "members_page",
                    $validated["members_page"] ?? 1,
                );
            $data["members"] = $members->items();
            $data["members_meta"] = [
                "current_page" => $members->currentPage(),
                "last_page" => $members->lastPage(),
                "total" => $members->total(),
            ];
        }
        return response()->json($data);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        if (is_string($request->input("name"))) {
            $request->merge(["name" => trim($request->input("name"))]);
        }
        $data = $request->validate($this->rules($role), [
            "name.unique" => "Tên vai trò đã tồn tại.",
        ]);
        $role = $this->access->write($request->user(), function (
            User $actor,
        ) use ($role, $data) {
            $this->access->require($actor, "role.update");
            $role = Role::lockForUpdate()->findOrFail($role->id);
            $renaming =
                isset($data["name"]) && trim($data["name"]) !== $role->name;
            $this->access->assertRoleEditable($actor, $role, $renaming);
            // Check ALL requested changes before applying any of them.
            if (array_key_exists("permissions", $data)) {
                $this->access->syncRolePermissions(
                    $actor,
                    $role,
                    $data["permissions"],
                );
            }
            if ($renaming) {
                abort_if(
                    in_array(
                        strtolower(trim($data["name"])),
                        config("access_control.reserved_roles"),
                        true,
                    ),
                    403,
                    "Tên vai trò hệ thống được bảo vệ.",
                );
                $role->update(["name" => trim($data["name"])]);
            }
            return $role;
        });
        return response()->json([
            "message" => "Đã cập nhật vai trò.",
            "data" => $role->load("permissions")->loadCount("users"),
        ]);
    }

    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            "permissions" => ["present", "array", "max:500"],
            "permissions.*" => [
                "required",
                "string",
                "distinct",
                Rule::exists("permissions", "name")->where("guard_name", "web"),
            ],
        ]);
        $role = $this->access->write($request->user(), function (
            User $actor,
        ) use ($role, $data) {
            $this->access->require($actor, "role.update");
            $role = Role::lockForUpdate()->findOrFail($role->id);
            $this->access->syncRolePermissions(
                $actor,
                $role,
                $data["permissions"],
            );
            return $role;
        });
        return response()->json([
            "message" => "Đã cập nhật quyền của vai trò.",
            "data" => $role->load("permissions")->loadCount("users"),
        ]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->access->write($request->user(), function (User $actor) use (
            $role,
        ) {
            $this->access->require($actor, "role.delete");
            $role = Role::lockForUpdate()->findOrFail($role->id);
            $this->access->assertRoleEditable($actor, $role);
            abort_if(
                in_array(
                    $role->name,
                    config("access_control.reserved_roles"),
                    true,
                ),
                403,
                "Không thể xóa vai trò hệ thống.",
            );
            abort_if(
                $role->users()->exists(),
                422,
                "Vai trò còn được sử dụng. Hãy chuyển vai trò của các tài khoản trước.",
            );
            $role->delete();
        });
        return response()->json(["message" => "Đã xóa vai trò."]);
    }
}