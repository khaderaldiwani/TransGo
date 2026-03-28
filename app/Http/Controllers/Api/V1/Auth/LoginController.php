<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangeInitialPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\ApiResponse;
use App\Models\Role;
use App\Services\AuthService;
use RuntimeException;

class LoginController extends Controller
{
    public function __construct(protected AuthService $authService)
    {
    }

    // public function login(LoginRequest $request)
    // {
    //     try {
    //         $data = $request->validated();
    //         $result = $this->authService->login($data,Role::ROLE_PASSENGER);
    //     } catch (RuntimeException $e) {
    //         return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
    //     }

    //     if ($result['must_change_password']) {
    //         return ApiResponse::success('يجب تغيير كلمة المرور قبل أول تسجيل دخول', 200, [
    //             'user' => $result['user'],
    //             'token' => null,
    //             'role' => $result['role'],
    //             'roles' => $result['roles'],
    //             'must_change_password' => true,
    //         ]);
    //     }

    //     return ApiResponse::success('تم تسجيل الدخول بنجاح', 200, [
    //         'user' => $result['user'],
    //         'token' => $result['token'],
    //         'role' => $result['role'],
    //         'roles' => $result['roles'],
    //         'must_change_password' => false,
    //     ]);
    // }

    public function changeInitialPassword(ChangeInitialPasswordRequest $request)
    {
        try {
            $this->authService->changeInitialPassword($request->validated());
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        }

        return ApiResponse::success('Password changed successfully. You can now log in.', 200);
    }
}
