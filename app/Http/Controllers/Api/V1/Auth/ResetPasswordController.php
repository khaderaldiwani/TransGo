<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\ApiResponse;
use App\Services\AuthService;
use Illuminate\Http\Request;
use RuntimeException;

class ResetPasswordController extends Controller
{
     public function __construct(protected AuthService $authService)
    {
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
           
        try {
           $result= $this->authService->resetPassword($request->validated());
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        }

        return ApiResponse::success('تم تغيير كلمة المرور بنجاح. يمكنك الآن تسجيل الدخول.', 200,$result);
    }
}
