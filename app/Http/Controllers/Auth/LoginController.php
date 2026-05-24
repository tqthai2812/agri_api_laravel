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
            'user' => $user,
            'message' => 'Đăng nhập thành công',
        ];

        if ($request->has('device_name')) {
            $data['token'] = $user->createToken($request->device_name)->plainTextToken;
        }

        return response()->json($data);
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->user()) {
            $request->user()->currentAccessToken()?->delete();
        }

        return response()->json([
            'message' => 'Đã đăng xuất thành công',
        ]);
    }
}
