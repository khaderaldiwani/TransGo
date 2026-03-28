<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    function logout(Request $request){
    try{
        $user = $request->user();
        $user->tokens()->delete();
        return ApiResponse::success('تم تسجيل الخروج بنجاح');
    }    catch(\Exception $e){
        return ApiResponse::error('حدث خطأ أثناء تسجيل الخروج.', 500);
    }
        
    }
}
