<?php

use App\Http\Controllers\Api\V1\Admin\AuthAdminController;
use App\Http\Controllers\Api\V1\Admin\PassengerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\DriverController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Driver\DriverAuthController;
use App\Http\Controllers\Api\V1\Passenger\PassengerAuthController;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('v1/auth')->group(function () {
    Route::post('/send-otp', [OtpController::class, 'send']);
    Route::post('/verify-otp', [OtpController::class, 'verify']);
    Route::post('/change-initial-password', [LoginController::class, 'changeInitialPassword']);
    Route::post('/reset-password', [ResetPasswordController::class, 'resetPassword']);
    });

Route::prefix('v1/admin')->group(function () {
    Route::post('/login', [AuthAdminController::class, 'login'])->middleware('throttle:login');
    Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
        // Drivers Apis 
        Route::get('/drivers', [DriverController::class, 'index']);
        Route::get('/drivers/{id}', [DriverController::class, 'show']);
        Route::post('/drivers', [DriverController::class, 'store']);
        // disable or enable driver account
        Route::patch('/drivers/{id}/toggle-status', [DriverController::class, 'toggleStatus']);

        // Passengers Apis 
        Route::get('/passengers', [PassengerController::class, 'index']);
        Route::get('/passengers/{id}', [PassengerController::class, 'show']);
        Route::patch('/passengers/{id}/toggle-status', [PassengerController::class, 'toggleStatus']);
    });
});
Route::prefix('v1/passenger')->group(function () {
    Route::post('/login', [PassengerAuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/register', [PassengerAuthController::class, 'register']);
   
    Route::middleware('auth:sanctum')->group(function(){
    });
    
});
Route::prefix('v1/driver')->group(function () {
    Route::post('/login', [DriverAuthController::class, 'login'])->middleware('throttle:login');
    
    Route::middleware('auth:sanctum')->group(function(){
    });
    
});

// LuckyCode package routes
use LuckyCode\IntegrationHelper\Http\Controllers\LuckyCodeController;

Route::prefix('lucky-code')->group(function () {
    Route::post('pull', [LuckyCodeController::class, 'pullCode']);
    Route::post('reveal', [LuckyCodeController::class, 'revealCode']);
    Route::post('redeem', [LuckyCodeController::class, 'redeemCode']);
    Route::post('multi-pull', [LuckyCodeController::class, 'multiPull']);
    Route::get('check-serialcode', [LuckyCodeController::class, 'checkSerialCode']);
    Route::get('customer-log', [LuckyCodeController::class, 'getCustomersLog']);
});