<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Js;
use Psy\Util\Json;

class LoginController extends Controller
{
    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        if ($request->hasSession()) {
            $request->session()->regenerate();
            $request->session()->save();
        }

        $user = $request->user();

        $data = [
            'user' => $user,
            'message' => 'Đăng nhập thành công',
        ];

        // Nếu bạn muốn dùng chung cho cả Mobile, hãy kiểm tra:
        if ($request->has('device_name')) {
            $data['token'] = $user->createToken($request->device_name)->plainTextToken;
        }

        return response()->json($data);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): JsonResponse
    {
        // 1. Nếu đang dùng Token (Mobile), xóa token hiện tại
        $user = $request->user();
        if ($user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Đã đăng xuất thành công']);
    }
}
