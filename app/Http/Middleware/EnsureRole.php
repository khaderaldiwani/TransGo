<?php

namespace App\Http\Middleware;

use App\Http\Resources\ApiResponse;
use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        $user = $request->user();

        if (!$user) {
            return ApiResponse::error('غير مصرح.', 401);
        }

        if (!$user->hasAnyRole($roles)) {
            return ApiResponse::error('ليس لديك صلاحية للوصول إلى هذا المورد.', 403);
        }

        return $next($request);
    }
}
