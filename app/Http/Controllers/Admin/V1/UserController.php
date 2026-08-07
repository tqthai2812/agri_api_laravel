<?php

namespace App\Http\Controllers\Admin\V1;

use App\Contracts\Services\ImageUploadServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\User\StoreUserRequest;
use App\Http\Requests\Admin\User\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

class UserController extends Controller implements HasMiddleware
{
    public function __construct(
        protected ImageUploadServiceInterface $imageUploadService
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:user.view', only: ['index', 'show']),
            new Middleware('permission:user.create', only: ['store']),
            new Middleware('permission:user.update', only: ['update']),
            new Middleware('permission:user.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $perPage = $request->query('per_page', 15);
        $role = $request->query('role');

        $users = User::query()
            ->select([
                'id',
                'name',
                'email',
                'phone_number',
                'avatar',
                'role',
                'is_active',
                'email_verified_at',
                'created_at',
            ])
            ->with('roles:id,name,guard_name')
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone_number', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('is_active'), function ($query) use ($request) {
                $query->where(
                    'is_active',
                    filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN)
                );
            })
            ->when($role, function ($query) use ($role) {
                $query->where(function ($q) use ($role) {
                    $q->where('role', $role)
                        ->orWhereHas('roles', function ($roleQuery) use ($role) {
                            $roleQuery->where('name', $role);
                        });
                });
            })
            ->latest()
            ->paginate($perPage);

        $users->getCollection()->transform(function ($user) {
            return $this->formatUser($user);
        });

        return response()->json([
            'message' => 'Lấy danh sách người dùng thành công.',
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        DB::beginTransaction();

        try {
            $roles = $data['roles'] ?? ['customer'];

            unset($data['roles']);

            if ($request->hasFile('avatar')) {
                $data['avatar'] = $this->imageUploadService->upload(
                    $request->file('avatar'),
                    'avatars'
                );
            }

            $data['password'] = Hash::make($data['password']);
            $data['is_active'] = $data['is_active'] ?? true;
            $data['role'] = $roles[0] ?? 'customer';

            $user = User::create($data);

            app()[PermissionRegistrar::class]->forgetCachedPermissions();
            $user->syncRoles($roles);

            DB::commit();

            return response()->json([
                'message' => 'Thêm người dùng thành công.',
                'data' => $this->formatUser($user->fresh()->load('roles')),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Lỗi khi thêm người dùng.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(User $user): JsonResponse
    {
        $user->load('roles:id,name,guard_name');

        return response()->json([
            'message' => 'Lấy chi tiết người dùng thành công.',
            'data' => $this->formatUser($user),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        if ($user->id === auth()->id() && array_key_exists('is_active', $data) && !$data['is_active']) {
            return response()->json([
                'message' => 'Bạn không thể tự khóa tài khoản của chính mình.',
            ], 403);
        }

        if ($user->hasRole('admin') && !auth()->user()->hasRole('admin')) {
            return response()->json([
                'message' => 'Bạn không có quyền chỉnh sửa Admin hệ thống.',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $roles = $data['roles'] ?? null;

            unset($data['roles']);

            if (empty($data['password'])) {
                unset($data['password']);
            } else {
                $data['password'] = Hash::make($data['password']);
            }

            if ($request->hasFile('avatar')) {
                $data['avatar'] = $this->imageUploadService->upload(
                    $request->file('avatar'),
                    'avatars',
                    $user->avatar
                );
            }

            $user->update($data);

            if (is_array($roles)) {
                if (!auth()->user()->hasPermissionTo('user.assign-role')) {
                    return response()->json([
                        'message' => 'Bạn không có quyền gán vai trò.',
                    ], 403);
                }

                app()[PermissionRegistrar::class]->forgetCachedPermissions();

                $user->syncRoles($roles);

                if (Schema::hasColumn('users', 'role')) {
                    $user->update([
                        'role' => $roles[0] ?? 'customer',
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Cập nhật người dùng thành công.',
                'data' => $this->formatUser($user->fresh()->load('roles')),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Lỗi khi cập nhật người dùng.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(User $user): JsonResponse
    {
        if ($user->id === auth()->id()) {
            return response()->json([
                'message' => 'Bạn không thể tự xóa tài khoản của chính mình.',
            ], 403);
        }

        if ($user->hasRole('admin') && !auth()->user()->hasRole('admin')) {
            return response()->json([
                'message' => 'Bạn không có quyền xóa Admin hệ thống.',
            ], 403);
        }

        DB::beginTransaction();

        try {
            if ($user->avatar) {
                $this->imageUploadService->delete($user->avatar);
            }

            $user->delete();

            DB::commit();

            return response()->json([
                'message' => 'Xóa người dùng thành công.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Lỗi khi xóa người dùng.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function formatUser(User $user): array
    {
        return array_merge($user->toArray(), [
            'avatar_url' => $user->avatar ? asset('storage/' . $user->avatar) : null,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }
}
