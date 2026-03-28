<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\ApiResponse;
use App\Models\Role;
use App\Services\AuthService;
use RuntimeException;

class AuthAdminController extends Controller
{
    
    function  __construct(protected AuthService $authService)
    {
    }

    function login(LoginRequest $request){
         
    try {
         $data = $request->validated();
         $result=$this->authService->login($data,Role::ROLE_ADMIN,Role::ROLE_EMPLOYEE);

    }
    catch(RuntimeException $e){
        return ApiResponse::error($e->getMessage(), $e->getCode());
    }
    if ($result['must_change_password']) {
        return ApiResponse::success('يجب تغيير كلمة المرور قبل أول تسجيل دخول', 200,
         [
            'user' => $result['user'],
            'token' => null,
            'role' => $result['role'],
            'roles' => $result['roles'],
            'must_change_password' => true,
        ]);
    }
    return ApiResponse::success('تم تسجيل الدخول بنجاح', 200, [
        'user' => $result['user'],
        'token' => $result['token'],
        'role' => $result['role'],
        'roles' => $result['roles'],
        'must_change_password' => $result['must_change_password'],
    ]);
    }
}
