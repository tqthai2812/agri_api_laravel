<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // Public pages remain public; a locked account can always log out.
        if (
            !$request->is("api/logout") &&
            ($user = $request->user()) &&
            !$user->is_active
        ) {
            $token = $user->currentAccessToken();
            if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
                $token->delete();
            }
            if ($request->hasSession()) {
                \Illuminate\Support\Facades\Auth::guard("web")->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            return response()->json(
                [
                    "message" => "Tài khoản đã bị khóa.",
                    "code" => "ACCOUNT_INACTIVE",
                ],
                403,
            );
        }
        return $next($request);
    }
}