<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

class RegisteredUserController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                'unique:' . User::class,
            ],
            'password' => [
                'required',
                'confirmed',
                Rules\Password::defaults(),
            ],
        ]);

        $verifiedEmail = EmailVerificationCode::where('email', $request->email)
            ->where('verified', true)
            ->where('expires_at', '>', now())
            ->first();

        if (!$verifiedEmail) {
            throw ValidationException::withMessages([
                'email' => [
                    'Email chưa được xác thực. Vui lòng xác thực email trước khi đăng ký.',
                ],
            ]);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'role' => 'customer',
            'email_verified_at' => now(),
            'password' => Hash::make($request->string('password')),
        ]);

        $user->assignRole('customer');

        event(new Registered($user));

        Auth::login($user);

        $verifiedEmail->delete();

        $data = [
            'user' => $user,
            'message' => 'Đăng ký thành công',
        ];

        if ($request->has('device_name')) {
            $data['token'] = $user->createToken($request->device_name)->plainTextToken;
        }

        return response()->json($data, 201);
    }
}
