<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:permission.view', only: ['index']),
        ];
    }

    public function index(): JsonResponse
    {
        $permissions = Permission::where('guard_name', 'web')
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get()
            ->groupBy(function ($permission) {
                return Str::before($permission->name, '.');
            });

        return response()->json([
            'message' => 'Lấy danh sách quyền thành công.',
            'data' => $permissions,
        ]);
    }
}
