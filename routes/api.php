<?php

use App\Http\Controllers\Api\V1\Admin\AuthAdminController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\DriverController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Driver\DriverAuthController;
use App\Http\Controllers\Api\V1\Driver\TripController;
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
    Route::middleware('auth:sanctum')->group(function(){
        Route::post('/drivers', [DriverController::class, 'store']);
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
        Route::post('/trips', [TripController::class, 'store']);
    });
    
});
