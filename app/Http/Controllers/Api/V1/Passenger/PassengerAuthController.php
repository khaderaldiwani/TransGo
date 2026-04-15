<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\ApiResponse;
use App\Models\Role;
use App\Services\AuthService;
use Exception;
use Illuminate\Http\Request;
use RuntimeException;
use Illuminate\Validation\ValidationException;

class PassengerAuthController extends Controller
{
     function  __construct(protected AuthService $authService)
    {
    }

    function login(LoginRequest $request){
         
    try {
         $data = $request->validated();
         $result=$this->authService->login($data,Role::ROLE_PASSENGER);

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
    function register(RegisterRequest $request){
        
    try{
        $data = $request->validated();
          $result =$this->authService->register($data,Role::ROLE_PASSENGER);
            return ApiResponse::success('تم التسجيل بنجاح. يرجى التحقق من رقم هاتفك.', 201, 
            [
                'user' => $result['user'],
                'otp' => $result['otp'] ?? null,
            ]
            
            );

    }
    catch(RuntimeException $e){
        return ApiResponse::error($e->getMessage(), $e->getCode());
    }catch(ValidationException $e){
        return ApiResponse::error($e->getMessage(), $e->getCode());
    }
    
    }
}
