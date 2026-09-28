<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\SendRegisterOtpMail;
use App\Models\EmailVerificationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegisterOtpController extends Controller
{
    private const PURPOSE = 'registration';
    private const MAX_ATTEMPTS = 5;

    public function sendCode(Request $request): JsonResponse
    {
        $request->validate([
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                'unique:users,email',
            ],
        ], [
            'email.required' => 'Vui lòng nhập email.',
            'email.lowercase' => 'Vui lòng nhập email bằng chữ thường.',
            'email.email' => 'Email không hợp lệ.',
            'email.unique' => 'Email này đã được đăng ký.',
        ]);

        // Luồng hiện tại dùng Sanctum SPA session cookie.
        abort_unless(
            $request->hasSession(),
            500,
            'API chưa được cấu hình session cho Sanctum SPA.'
        );

        $email = $request->string('email')->toString();
        $code = (string) random_int(100000, 999999);

        // Chặn hai request gửi mã cùng email chạy đồng thời.
        $lock = Cache::lock(
            'register-otp-send:' . hash('sha256', $email),
            30
        );

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'email' => ['Yêu cầu trước đang được xử lý. Vui lòng thử lại sau.'],
            ]);
        }

        try {
            $otp = DB::transaction(function () use ($email, $code) {
                $latest = EmailVerificationCode::query()
                    ->where('email', $email)
                    ->where('purpose', self::PURPOSE)
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                if (
                    $latest?->created_at
                    && $latest->created_at->gt(now()->subSeconds(60))
                ) {
                    throw ValidationException::withMessages([
                        'email' => [
                            'Vui lòng chờ 60 giây giữa hai lần gửi mã.',
                        ],
                    ]);
                }

                // Vô hiệu hóa mã đăng ký cũ, giữ lại lịch sử.
                EmailVerificationCode::query()
                    ->where('email', $email)
                    ->where('purpose', self::PURPOSE)
                    ->whereNull('consumed_at')
                    ->update([
                        'consumed_at' => now(),
                        'updated_at' => now(),
                    ]);

                return EmailVerificationCode::create([
                    'email' => $email,
                    'code' => Hash::make($code),
                    'verified' => false,
                    'purpose' => self::PURPOSE,
                    'expires_at' => now()->addMinutes(10),
                    'consumed_at' => null,
                    'attempts' => 0,
                ]);
            });
        } finally {
            $lock->release();
        }

        $request->session()->forget('registration_otp');

        try {
            Mail::to($email)->send(new SendRegisterOtpMail($code));
        } catch (Throwable $exception) {
            // Mã của lần gửi thất bại không được dùng để đăng ký.
            $otp->update([
                'consumed_at' => now(),
            ]);

            report($exception);

            return response()->json([
                'message' => 'Không gửi được email. Vui lòng thử lại sau.',
            ], 503);
        }

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
                'string',
                'digits:6',
            ],
        ], [
            'email.required' => 'Vui lòng nhập email.',
            'email.email' => 'Email không hợp lệ.',
            'code.required' => 'Vui lòng nhập mã xác thực.',
            'code.digits' => 'Mã xác thực phải gồm 6 chữ số.',
        ]);

        abort_unless(
            $request->hasSession(),
            500,
            'API chưa được cấu hình session cho Sanctum SPA.'
        );

        $email = $request->string('email')->toString();
        $code = $request->string('code')->toString();

        $result = DB::transaction(function () use ($email, $code) {
            $otp = EmailVerificationCode::query()
                ->where('email', $email)
                ->where('purpose', self::PURPOSE)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $otp || $otp->consumed_at !== null) {
                return [
                    'error' => 'Mã không tồn tại hoặc đã bị vô hiệu hóa. Vui lòng gửi mã mới.',
                ];
            }

            if ($otp->expires_at->lte(now())) {
                $otp->update(['consumed_at' => now()]);

                return [
                    'error' => 'Mã xác thực đã hết hạn. Vui lòng gửi mã mới.',
                ];
            }

            if ($otp->attempts >= self::MAX_ATTEMPTS) {
                $otp->update(['consumed_at' => now()]);

                return [
                    'error' => 'Bạn đã nhập sai quá nhiều lần. Vui lòng gửi mã mới.',
                ];
            }

            if (! Hash::check($code, $otp->code)) {
                $attempts = $otp->attempts + 1;

                $otp->update([
                    'attempts' => $attempts,
                    'consumed_at' => $attempts >= self::MAX_ATTEMPTS
                        ? now()
                        : null,
                ]);

                return [
                    'error' => $attempts >= self::MAX_ATTEMPTS
                        ? 'Bạn đã nhập sai 5 lần. Vui lòng gửi mã mới.'
                        : 'Mã xác thực không đúng.',
                ];
            }

            $otp->update([
                'verified' => true,
            ]);

            return [
                'id' => $otp->id,
            ];
        });

        // Ném lỗi SAU transaction để số lần nhập sai vẫn được lưu.
        if (isset($result['error'])) {
            throw ValidationException::withMessages([
                'code' => [$result['error']],
            ]);
        }

        $request->session()->put('registration_otp', [
            'id' => $result['id'],
            'email' => $email,
        ]);

        return response()->json([
            'message' => 'Xác thực email thành công.',
        ]);
    }
}
