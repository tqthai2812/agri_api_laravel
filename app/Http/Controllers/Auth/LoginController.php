<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function store(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        if ($request->hasSession()) {
            $request->session()->regenerate();
            $request->session()->save();
        }

        $user = $request->user();

        $data = [
            'user' => array_merge($user->toArray(), [
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ]),
            'message' => 'Đăng nhập thành công',
        ];

        if ($request->has('device_name')) {
            $data['token'] = $user->createToken($request->device_name)->plainTextToken;
        }

        return response()->json($data);
    }

    public function destroy(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        // Bearer token: chỉ thu hồi token đang được sử dụng.
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        }

        // SPA cookie: kết thúc session hiện tại.
        if ($request->hasSession()) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Đã đăng xuất thành công',
        ]);
    }
}
