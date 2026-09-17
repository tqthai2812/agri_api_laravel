<?php

namespace App\Http\Controllers\Client\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\Profile\ChangePasswordRequest;
use App\Http\Requests\Client\Profile\UpdateProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProfileController extends Controller
{
    public function show(): JsonResponse
    {
        $user = auth()->user();

        return response()->json([
            'message' => 'Lấy thông tin hồ sơ thành công.',
            'user' => $this->userData($user),
            'data' => [
                'user' => $this->userData($user),
            ],
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = auth()->user();

        $data = $request->validated();

        $updateData = [
            'name' => $data['name'],
            'phone_number' => $data['phone_number'] ?? null,
        ];

        if ($request->hasFile('avatar')) {
            $this->deleteOldAvatar($user->avatar);

            $updateData['avatar'] = $request
                ->file('avatar')
                ->store('avatars', 'public');
        }

        $user->update($updateData);

        $user = $user->fresh();

        return response()->json([
            'message' => 'Cập nhật hồ sơ thành công.',
            'user' => $this->userData($user),
            'data' => [
                'user' => $this->userData($user),
            ],
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = auth()->user();

        $user->forceFill([
            'password' => Hash::make($request->validated('password')),
        ])->save();

        return response()->json([
            'message' => 'Đổi mật khẩu thành công.',
        ]);
    }

    private function userData($user): array
    {
        return [
            'id' => $user->id,

            'name' => $user->name,
            'email' => $user->email,
            'phone_number' => $user->phone_number,

            'avatar' => $this->avatarUrl($user->avatar),
            'avatar_path' => $user->avatar,

            'role' => $user->role,
            'is_active' => (bool) $user->is_active,

            'roles' => method_exists($user, 'getRoleNames')
                ? $user->getRoleNames()
                : [],

            'permissions' => method_exists($user, 'getAllPermissions')
                ? $user->getAllPermissions()->pluck('name')
                : [],

            'created_at' => $user->created_at?->toDateTimeString(),
            'updated_at' => $user->updated_at?->toDateTimeString(),
        ];
    }

    private function avatarUrl(?string $avatar): ?string
    {
        if (!$avatar) {
            return null;
        }

        if (Str::startsWith($avatar, ['http://', 'https://'])) {
            return $avatar;
        }

        return asset('storage/' . ltrim($avatar, '/'));
    }

    private function deleteOldAvatar(?string $avatar): void
    {
        if (!$avatar) {
            return;
        }

        if (Str::startsWith($avatar, ['http://', 'https://'])) {
            return;
        }

        Storage::disk('public')->delete($avatar);
    }
}
