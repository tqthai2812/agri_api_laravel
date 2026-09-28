<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
                'unique:users,email',
            ],
            'password' => [
                'required',
                'string',
                'confirmed',
                Rules\Password::defaults(),
            ],
            'device_name' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        abort_unless(
            $request->hasSession(),
            500,
            'API chưa được cấu hình session cho Sanctum SPA.'
        );

        $email = $request->string('email')->toString();
        $proof = $request->session()->get('registration_otp');

        if (
            ! is_array($proof)
            || ($proof['email'] ?? null) !== $email
            || empty($proof['id'])
        ) {
            throw ValidationException::withMessages([
                'email' => [
                    'Vui lòng xác thực email trên trình duyệt này trước khi đăng ký.',
                ],
            ]);
        }

        $user = DB::transaction(function () use ($request, $email, $proof) {
            $otp = EmailVerificationCode::query()
                ->whereKey($proof['id'])
                ->where('email', $email)
                ->where('purpose', 'registration')
                ->lockForUpdate()
                ->first();

            if (
                ! $otp
                || ! $otp->verified
                || $otp->consumed_at !== null
                || $otp->expires_at->lte(now())
                || $otp->attempts >= 5
            ) {
                throw ValidationException::withMessages([
                    'email' => [
                        'Xác thực email đã hết hạn hoặc không còn hiệu lực. Vui lòng gửi mã mới.',
                    ],
                ]);
            }

            // Kiểm tra lại trong transaction.
            if (User::where('email', $email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['Email này đã được đăng ký.'],
                ]);
            }

            $user = User::create([
                'name' => $request->string('name')->trim()->toString(),
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Hash::make(
                    $request->string('password')->toString()
                ),
                'role' => User::ROLE_CUSTOMER,
                'is_active' => true,
            ]);

            // Giữ nguyên cơ chế phân quyền hiện tại.
            $user->assignRole(User::ROLE_CUSTOMER);

            $otp->update([
                'consumed_at' => now(),
            ]);

            return $user;
        });

        $request->session()->forget('registration_otp');

        event(new Registered($user));

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        $data = [
            'user' => array_merge($user->toArray(), [
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ]),
            'message' => 'Đăng ký thành công',
        ];

        if ($request->filled('device_name')) {
            $data['token'] = $user
                ->createToken($request->string('device_name')->toString())
                ->plainTextToken;
        }

        return response()->json($data, 201);
    }
}
