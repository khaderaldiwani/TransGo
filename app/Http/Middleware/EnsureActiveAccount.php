<?php

namespace App\Http\Middleware;

use App\Http\Resources\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::error('غير مصرح.', 401);
        }

        if ($user->account_status === User::STATUS_ACTIVE) {
            return $next($request);
        }

        if ($this->isLogoutRequest($request)) {
            return $next($request);
        }

        return ApiResponse::error('الحساب غير مفعل.', 403);
    }

    private function isLogoutRequest(Request $request): bool
    {
        return $request->is('api/v1/admin/logout')
            || $request->is('api/v1/auth/logout')
            || $request->is('api/v1/driver/logout')
            || $request->is('api/v1/passenger/logout');
    }
}
