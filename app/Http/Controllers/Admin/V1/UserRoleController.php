<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Spatie\Permission\PermissionRegistrar;

class UserRoleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:user.assign-role', only: ['update']),
        ];
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => [
                'string',
                Rule::exists('roles', 'name')->where('guard_name', 'web'),
            ],
        ], [
            'roles.required' => 'Vui lòng chọn vai trò.',
            'roles.array' => 'Danh sách vai trò không hợp lệ.',
            'roles.*.exists' => 'Có vai trò không tồn tại.',
        ]);

        if ($user->id === auth()->id()) {
            return response()->json([
                'message' => 'Bạn không thể tự thay đổi vai trò của chính mình.',
            ], 403);
        }

        if ($user->hasRole('admin') && !auth()->user()->hasRole('admin')) {
            return response()->json([
                'message' => 'Bạn không có quyền thay đổi vai trò của Admin hệ thống.',
            ], 403);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $user->syncRoles($validated['roles']);

        if (Schema::hasColumn('users', 'role')) {
            $user->update([
                'role' => $validated['roles'][0] ?? 'customer',
            ]);
        }

        $user = $user->fresh();

        return response()->json([
            'message' => 'Gán vai trò cho người dùng thành công.',
            'data' => [
                'user' => array_merge($user->toArray(), [
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ]),
            ],
        ]);
    }
}
