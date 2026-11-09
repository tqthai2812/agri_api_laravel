<?php

namespace App\Http\Controllers\Client\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\Profile\ChangePasswordRequest;
use App\Http\Requests\Client\Profile\UpdateProfileRequest;
use App\Http\Resources\AccessUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->profileResponse(
            $request,
            $request->user(),
            'Lấy thông tin hồ sơ thành công.',
        );
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $data = $request->validated();
        $updateData = [
            'name' => $data['name'],
            'phone_number' => $data['phone_number'] ?? null,
        ];
        $newAvatar = null;

        // Lưu ảnh mới trước; giữ nguyên ảnh cũ nếu ghi tệp hoặc cập nhật DB thất bại.
        if ($request->hasFile('avatar')) {
            $newAvatar = $request->file('avatar')->store('avatars', 'public');
            abort_unless($newAvatar, 503, 'Chưa lưu được ảnh đại diện. Vui lòng thử lại.');
            $updateData['avatar'] = $newAvatar;
        }

        try {
            [$user, $oldAvatar] = DB::transaction(function () use ($request, $updateData) {
                $user = User::query()
                    ->lockForUpdate()
                    ->findOrFail($request->user()->getKey());
                $oldAvatar = $user->avatar;

                // Chỉ cho cập nhật các trường hồ sơ; không nhận vai trò hoặc quyền.
                $user->fill($updateData)->save();

                return [$user, $oldAvatar];
            });
        } catch (Throwable $exception) {
            if ($newAvatar) {
                $this->deleteAvatar($newAvatar);
            }

            throw $exception;
        }

        // Chỉ dọn ảnh cũ sau khi DB đã trỏ sang ảnh mới thành công.
        if ($newAvatar && $oldAvatar !== $newAvatar) {
            $this->deleteAvatar($oldAvatar);
        }

        return $this->profileResponse($request, $user, 'Cập nhật hồ sơ thành công.');
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $request->user()->forceFill([
            'password' => Hash::make($request->validated('password')),
        ])->save();

        return response()->json([
            'message' => 'Đổi mật khẩu thành công.',
        ]);
    }

    private function profileResponse(Request $request, User $user, string $message): JsonResponse
    {
        // Dùng cùng nguồn vai trò/quyền với /api/user của bản phân quyền Spatie.
        $data = (new AccessUserResource($user))->resolve($request);

        // Giữ cấu trúc ảnh và các nhánh phản hồi mà trang hồ sơ khách hàng đang dùng.
        $data['avatar_path'] = $user->avatar;
        $data['avatar'] = $this->avatarUrl($user->avatar);
        $data['avatar_url'] = $data['avatar'];
        $data['created_at'] = $user->created_at?->toDateTimeString();
        $data['updated_at'] = $user->updated_at?->toDateTimeString();

        return response()->json([
            'message' => $message,
            'user' => $data,
            'data' => ['user' => $data],
        ]);
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

    private function deleteAvatar(?string $avatar): void
    {
        if (!$avatar || Str::startsWith($avatar, ['http://', 'https://'])) {
            return;
        }

        try {
            if (!Storage::disk('public')->delete($avatar)) {
                Log::warning('Chưa dọn được ảnh đại diện cũ.', ['path' => $avatar]);
            }
        } catch (Throwable $exception) {
            // Lỗi dọn tệp không được biến một hồ sơ đã lưu thành thông báo lưu thất bại.
            report($exception);
        }
    }
}