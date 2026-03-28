<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;

use App\Http\Requests\Auth\ChangeInitialPasswordRequest;
use App\Http\Resources\ApiResponse;
use App\Services\AuthService;
use RuntimeException;

class ChangeInitialPasswordController extends Controller
{
     public function __construct(protected AuthService $authService)
    {
    }

    public function changeInitialPassword(ChangeInitialPasswordRequest $request)
    {
           
        try {
            $this->authService->changeInitialPassword($request->validated());
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        }

        return ApiResponse::success('تم تغيير كلمة المرور بنجاح. يمكنك الآن تسجيل الدخول.', 200);
    }
}
