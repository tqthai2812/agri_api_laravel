<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use App\Mail\SendRegisterOtpMail;

class RegisterOtpController extends Controller
{
    public function sendCode(Request $request): JsonResponse
    {
        $request->validate([
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                'unique:' . User::class,
            ],
        ], [
            'email.required' => 'Vui lòng nhập email.',
            'email.email' => 'Email không hợp lệ.',
            'email.unique' => 'Email này đã được đăng ký.',
        ]);

        $code = (string) random_int(100000, 999999);

        EmailVerificationCode::where('email', $request->email)->delete();

        EmailVerificationCode::create([
            'email' => $request->email,
            'code' => $code,
            'verified' => false,
            'expires_at' => now()->addMinutes(10),
        ]);

        Mail::to($request->email)->send(new SendRegisterOtpMail($code));

        return response()->json([
            'message' => 'Mã xác thực đã được gửi đến email.',
        ]);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $request->validate([
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
            ],
            'code' => [
                'required',
                'digits:6',
            ],
        ], [
            'email.required' => 'Vui lòng nhập email.',
            'email.email' => 'Email không hợp lệ.',
            'code.required' => 'Vui lòng nhập mã xác thực.',
            'code.digits' => 'Mã xác thực phải gồm 6 chữ số.',
        ]);

        $otp = EmailVerificationCode::where('email', $request->email)
            ->where('code', $request->code)
            ->first();

        if (!$otp) {
            throw ValidationException::withMessages([
                'code' => ['Mã xác thực không đúng.'],
            ]);
        }

        if ($otp->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'code' => ['Mã xác thực đã hết hạn.'],
            ]);
        }

        $otp->update([
            'verified' => true,
        ]);

        return response()->json([
            'message' => 'Xác thực email thành công.',
        ]);
    }
}
