<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class LogoutController extends Controller
{
    function logout(Request $request){
    try{
        $request->user()?->currentAccessToken()?->delete();

        if ($request->bearerToken()) {
            PersonalAccessToken::findToken($request->bearerToken())?->delete();
        }
        return ApiResponse::success('تم تسجيل الخروج بنجاح');
    }    catch(\Exception $e){
        return ApiResponse::error('حدث خطأ أثناء تسجيل الخروج.', 500);
    }
        
    }
}
