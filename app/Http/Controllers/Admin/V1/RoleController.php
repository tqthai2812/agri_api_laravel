<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:role.view', only: ['index', 'show']),
            new Middleware('permission:role.create', only: ['store']),
            new Middleware('permission:role.update', only: ['update', 'syncPermissions']),
            new Middleware('permission:role.delete', only: ['destroy']),
            new Middleware('permission:role.assign-permission', only: ['syncPermissions']),
        ];
    }

    public function index(): JsonResponse
    {
        $roles = Role::with('permissions:id,name')
            ->where('guard_name', 'web')
            ->get();

        return response()->json([
            'message' => 'Lấy danh sách vai trò thành công.',
            'data' => $roles,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')->where('guard_name', 'web'),
            ],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [
                'string',
                Rule::exists('permissions', 'name')->where('guard_name', 'web'),
            ],
        ], [
            'name.required' => 'Vui lòng nhập tên vai trò.',
            'name.unique' => 'Vai trò này đã tồn tại.',
            'permissions.array' => 'Danh sách quyền không hợp lệ.',
            'permissions.*.exists' => 'Có quyền không tồn tại trong hệ thống.',
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'web',
        ]);

        if (!empty($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        return response()->json([
            'message' => 'Tạo vai trò thành công.',
            'data' => $role->load('permissions:id,name'),
        ], 201);
    }

    public function show(Role $role): JsonResponse
    {
        if ($role->guard_name !== 'web') {
            return response()->json([
                'message' => 'Vai trò không hợp lệ.',
            ], 404);
        }

        return response()->json([
            'message' => 'Lấy chi tiết vai trò thành công.',
            'data' => $role->load('permissions:id,name'),
        ]);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        if ($role->guard_name !== 'web') {
            return response()->json([
                'message' => 'Vai trò không hợp lệ.',
            ], 404);
        }

        if ($role->name === 'admin') {
            return response()->json([
                'message' => 'Không nên chỉnh sửa trực tiếp vai trò admin.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')
                    ->ignore($role->id)
                    ->where('guard_name', 'web'),
            ],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [
                'string',
                Rule::exists('permissions', 'name')->where('guard_name', 'web'),
            ],
        ], [
            'name.required' => 'Vui lòng nhập tên vai trò.',
            'name.unique' => 'Vai trò này đã tồn tại.',
            'permissions.array' => 'Danh sách quyền không hợp lệ.',
            'permissions.*.exists' => 'Có quyền không tồn tại trong hệ thống.',
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $role->update([
            'name' => $validated['name'],
        ]);

        if (array_key_exists('permissions', $validated)) {
            $role->syncPermissions($validated['permissions'] ?? []);
        }

        return response()->json([
            'message' => 'Cập nhật vai trò thành công.',
            'data' => $role->load('permissions:id,name'),
        ]);
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->guard_name !== 'web') {
            return response()->json([
                'message' => 'Vai trò không hợp lệ.',
            ], 404);
        }

        if ($role->name === 'admin') {
            return response()->json([
                'message' => 'Không thể xóa vai trò admin.',
            ], 403);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $role->delete();

        return response()->json([
            'message' => 'Xóa vai trò thành công.',
        ]);
    }

    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        if ($role->guard_name !== 'web') {
            return response()->json([
                'message' => 'Vai trò không hợp lệ.',
            ], 404);
        }

        if ($role->name === 'admin') {
            return response()->json([
                'message' => 'Không nên chỉnh sửa quyền của vai trò admin.',
            ], 403);
        }

        $validated = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => [
                'string',
                Rule::exists('permissions', 'name')->where('guard_name', 'web'),
            ],
        ], [
            'permissions.required' => 'Vui lòng chọn quyền.',
            'permissions.array' => 'Danh sách quyền không hợp lệ.',
            'permissions.*.exists' => 'Có quyền không tồn tại trong hệ thống.',
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $role->syncPermissions($validated['permissions']);

        return response()->json([
            'message' => 'Cập nhật quyền cho vai trò thành công.',
            'data' => $role->load('permissions:id,name'),
        ]);
    }
}
