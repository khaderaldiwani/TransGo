<?php

use App\Http\Controllers\Api\V1\Admin\AuthAdminController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\EmployeeController;
use App\Http\Controllers\Api\V1\Admin\PassengerController;
use App\Http\Controllers\Api\V1\Admin\TripController as AdminTripController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Api\V1\Admin\DriverController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Driver\DriverAuthController;
use App\Http\Controllers\Api\V1\Driver\TripController;
use App\Http\Controllers\Api\V1\Passenger\BookingController;
use App\Http\Controllers\Api\V1\Passenger\PassengerAuthController;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('v1/auth')->group(function () {
    Route::post('/send-otp', [OtpController::class, 'send']);
    Route::post('/verify-otp', [OtpController::class, 'verify']);
    Route::post('/change-initial-password', [LoginController::class, 'changeInitialPassword']);
    Route::post('/reset-password', [ResetPasswordController::class, 'resetPassword']);
    Route::post('/logout', [LogoutController::class, 'logout'])->middleware(['auth:sanctum']);

    });





Route::prefix('v1/admin')->group(function () {
    Route::post('/login', [AuthAdminController::class, 'login'])->middleware('throttle:login');
 
    Route::middleware(['auth:sanctum', 'active', 'role:admin'])->group(function () {
        // Employees Apis
        Route::get('/employees', [EmployeeController::class, 'index']);
        Route::get('/employees/{id}', [EmployeeController::class, 'show']);
        Route::post('/employees', [EmployeeController::class, 'store']);
        Route::patch('/employees/{id}', [EmployeeController::class, 'update']);
        Route::patch('/employees/{id}/disable', [EmployeeController::class, 'disable']);
        Route::patch('/employees/{id}/enable', [EmployeeController::class, 'enable']);
        Route::get('/audit-logs', [AuditLogController::class, 'index']);

        // Drivers Apis 
        Route::get('/drivers', [DriverController::class, 'index']);
        Route::get('/drivers/{id}', [DriverController::class, 'show']);
        Route::get('/wallet-topups', [DriverController::class, 'walletTopUps']);
        ///////
        Route::post('/drivers', [DriverController::class, 'store']);
        Route::post('/drivers/{id}/wallet/top-up', [DriverController::class, 'topUpWallet']);
        // disable or enable driver account
        Route::patch('/drivers/{id}/toggle-status', [DriverController::class, 'toggleStatus']);

        // Passengers Apis 
        Route::get('/passengers', [PassengerController::class, 'index']);
        Route::get('/passengers/{id}', [PassengerController::class, 'show']);
        Route::patch('/passengers/{id}/toggle-status', [PassengerController::class, 'toggleStatus']);
    });

    Route::middleware(['auth:sanctum', 'active', 'role:admin,employee'])->group(function () {
        Route::get('/trips', [AdminTripController::class, 'index']);
        Route::get('/trips/{id}', [AdminTripController::class, 'show'])->whereNumber('id');
        Route::get('/trips/delayed', [AdminTripController::class, 'delayed']);
        Route::post('/trips/{id}/cancel', [AdminTripController::class, 'cancel'])->whereNumber('id');
        Route::get('/bookings', [AdminBookingController::class, 'index']);
     //   Route::get('/trips/tracking/active', [AdminTripController::class, 'activeTracking']);
        
        
    });
});

Route::prefix('v1/passenger')->group(function () {
    Route::post('/login', [PassengerAuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/register', [PassengerAuthController::class, 'register']);
   
    Route::middleware(['auth:sanctum', 'role:passenger'])->group(function(){
        Route::post('/bookings', [BookingController::class, 'store']);
    });
    
});
Route::prefix('v1/driver')->group(function () {
    Route::post('/login', [DriverAuthController::class, 'login'])->middleware('throttle:login');
    
    Route::middleware(['auth:sanctum', 'active', 'role:driver'])->group(function(){
        Route::get('/trips', [TripController::class, 'index']);
        Route::get('/trips/pending', [TripController::class, 'pending']);
        Route::get('/trips/current', [TripController::class, 'current']);
        Route::get('/trips/completed', [TripController::class, 'completed']);
        Route::get('/trips/canceled', [TripController::class, 'canceled']);
        //
        Route::get('/trips/{id}/attendance', [TripController::class, 'attendance'])->whereNumber('id');
        Route::get('/trips/{id}', [TripController::class, 'show'])->whereNumber('id');
        Route::post('/trips/{id}/cancel', [TripController::class, 'cancel'])->whereNumber('id');
        Route::post('/trips/preview', [TripController::class, 'preview']);
        Route::post('/trips', [TripController::class, 'store']);
    });
    
});

// //LuckyCode package routes
// use LuckyCode\IntegrationHelper\Http\Controllers\LuckyCodeController;

// Route::prefix('lucky-code')->group(function () {
//     Route::post('pull', [LuckyCodeController::class, 'pullCode']);
//     Route::post('reveal', [LuckyCodeController::class, 'revealCode']);
//     Route::post('redeem', [LuckyCodeController::class, 'redeemCode']);
//     Route::post('multi-pull', [LuckyCodeController::class, 'multiPull']);
//     Route::get('check-serialcode', [LuckyCodeController::class, 'checkSerialCode']);
//     Route::get('customer-log', [LuckyCodeController::class, 'getCustomersLog']);
// });
