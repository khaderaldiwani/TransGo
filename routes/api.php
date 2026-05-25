<?php

use App\Http\Controllers\Api\V1\Admin\AuthAdminController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\CommissionRateController;
use App\Http\Controllers\Api\V1\Admin\ComplaintController as AdminComplaintController;
use App\Http\Controllers\Api\V1\Admin\EmployeeController;
use App\Http\Controllers\Api\V1\Admin\PassengerController;
use App\Http\Controllers\Api\V1\Admin\RatingController as AdminRatingController;
use App\Http\Controllers\Api\V1\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\V1\Admin\TripController as AdminTripController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Api\V1\Admin\DriverController;
use App\Http\Controllers\Api\V1\Admin\DriverPerformanceController;
use App\Http\Controllers\Api\V1\Admin\AppUsageReportController;
use App\Http\Controllers\Api\V1\Admin\ComplaintReportController;
use App\Http\Controllers\Api\V1\Admin\ProfileController as AdminProfileController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\BookingStatusController;
use App\Http\Controllers\Api\V1\ComplaintController;
use App\Http\Controllers\Api\V1\Driver\ComplaintController as DriverComplaintController;
use App\Http\Controllers\Api\V1\Driver\DriverAuthController;
use App\Http\Controllers\Api\V1\Driver\FinancialReportController as DriverFinancialReportController;
use App\Http\Controllers\Api\V1\Driver\RatingController as DriverRatingController;
use App\Http\Controllers\Api\V1\Driver\ReceiptController as DriverReceiptController;
use App\Http\Controllers\Api\V1\Driver\ProfileController as DriverProfileController;
use App\Http\Controllers\Api\V1\Driver\TripController;
use App\Http\Controllers\Api\V1\Driver\WalletController as DriverWalletController;
use App\Http\Controllers\Api\V1\Passenger\BookingController;
use App\Http\Controllers\Api\V1\Passenger\ProfileController as PassengerProfileController;
use App\Http\Controllers\Api\V1\Passenger\ComplaintController as PassengerComplaintController;
use App\Http\Controllers\Api\V1\Passenger\PassengerAuthController;
use App\Http\Controllers\Api\V1\Passenger\RatingController as PassengerRatingController;
use App\Http\Controllers\Api\V1\Passenger\ReceiptController as PassengerReceiptController;
use App\Http\Controllers\Api\V1\Passenger\TripController as PassengerTripController;
use App\Http\Controllers\Api\V1\Passenger\WalletController as PassengerWalletController;
use App\Http\Controllers\Api\V1\GovernorateController;
use App\Http\Controllers\Api\V1\TripStatusController;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/v1/trip-statuses', [TripStatusController::class, 'index']);
Route::get('/v1/booking-statuses', [BookingStatusController::class, 'index']);
//new
//governorates public endpoint for web
Route::get('/v1/governorates', [GovernorateController::class, 'index']);

Route::prefix('v1/auth')->group(function () {
    Route::post('/send-otp', [OtpController::class, 'send']);
    Route::post('/verify-otp', [OtpController::class, 'verify']);
    Route::post('/change-initial-password', [LoginController::class, 'changeInitialPassword']);
    Route::post('/reset-password', [ResetPasswordController::class, 'resetPassword']);
    Route::post('/logout', [LogoutController::class, 'logout'])->middleware(['auth:sanctum']);

    });





Route::prefix('v1/admin')->group(function () {
    //auth
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
        Route::get('/drivers/low-rated', [AdminRatingController::class, 'lowRatedDrivers']);
        Route::get('/drivers/{id}', [DriverController::class, 'show']);
        ///////
        Route::post('/drivers', [DriverController::class, 'store']);
        // disable or enable driver account
        Route::patch('/drivers/{id}/toggle-status', [DriverController::class, 'toggleStatus']);

        // Passengers Apis  + reports
        Route::get('/passengers', [PassengerController::class, 'index']);
        Route::get('/passengers/{id}', [PassengerController::class, 'show']);
        Route::patch('/passengers/{id}/toggle-status', [PassengerController::class, 'toggleStatus']);
        //wallet top-up history for both drivers and passengers
        Route::post('/drivers/{id}/wallet/top-up', [DriverController::class, 'topUpWallet']);
        Route::get('/driver-wallet-topups', [DriverController::class, 'driverWalletTopUps']);
        Route::get('/passenger-wallet-topups', [PassengerController::class, 'walletTopUps']);
        Route::post('/passengers/{id}/wallet/top-up', [PassengerController::class, 'topUpWallet']);
        Route::get('/wallet-topups', [DriverController::class, 'walletTopUps']);
        
        //commission
        Route::get('/commission-rates', [CommissionRateController::class, 'index']);
        Route::get('/commission-rates/current', [CommissionRateController::class, 'current']);
        Route::post('/commission-rates', [CommissionRateController::class, 'store']);

    });

    Route::middleware(['auth:sanctum', 'active', 'role:admin,employee'])->group(function () {
        Route::get('/trips', [AdminTripController::class, 'index']);
        Route::get('/trips/tracking/active', [AdminTripController::class, 'activeTracking']);
        Route::get('/trips/{id}/tracking', [AdminTripController::class, 'tracking'])->whereNumber('id');
        Route::get('/trips/{id}', [AdminTripController::class, 'show'])->whereNumber('id');
        Route::get('/trips/delayed', [AdminTripController::class, 'delayed']);
        Route::post('/trips/{id}/cancel', [AdminTripController::class, 'cancel'])->whereNumber('id');
        Route::get('/bookings', [AdminBookingController::class, 'index']);
        Route::get('/bookings/{bookingId}', [AdminBookingController::class, 'show']);
        Route::patch('/bookings/{bookingId}/status', [AdminBookingController::class, 'updateStatus']);
     
         // Complaints
        Route::get('/complaints', [AdminComplaintController::class, 'index']);
        Route::get('/complaints/{complaintId}', [AdminComplaintController::class, 'show'])->whereNumber('complaintId');
        Route::patch('/complaints/{complaintId}/status', [AdminComplaintController::class, 'updateStatus']);
        Route::get('/complaints/{complaintId}/audit', [AdminComplaintController::class, 'auditTrail'])->whereNumber('complaintId');
        Route::get('/ratings', [AdminRatingController::class, 'index']);
        Route::get('/ratings/list', [AdminRatingController::class, 'listRatings']);
        Route::patch('/ratings/{ratingId}/hide', [AdminRatingController::class, 'hide'])->whereNumber('ratingId');
    
        //reports
        Route::get('/reports/trips-by-governorates', [AdminReportController::class, 'tripsByGovernorates']);
       //money
        Route::get('/reports/revenue', [AdminReportController::class, 'revenue']);
        Route::get('/reports/driver-earnings', [AdminReportController::class, 'driverEarnings']);
        // profile
        Route::get('/me', [AdminProfileController::class, 'show']);

        
        // Driver Performance Report
        Route::get('/driver-performance/report', [DriverPerformanceController::class, 'report']);
        Route::get('/driver-performance/export', [DriverPerformanceController::class, 'export']);
        
        // Application Usage Report
        Route::get('/app-usage/report', [AppUsageReportController::class, 'report']);
        
        // Complaint Report
        Route::get('/complaints/report', [ComplaintReportController::class, 'report']);
    });
});

Route::prefix('v1/passenger')->group(function () {
    Route::post('/login', [PassengerAuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/register', [PassengerAuthController::class, 'register']);
   
    Route::middleware(['auth:sanctum', 'role:passenger'])->group(function(){
        Route::get('/trips', [PassengerTripController::class, 'index']);
        Route::get('/trips/current', [PassengerTripController::class, 'current']);
        Route::get('/trips/pending', [PassengerTripController::class, 'pending']);
        Route::get('/trips/completed', [PassengerTripController::class, 'completed']);
        Route::get('/trips/canceled', [PassengerTripController::class, 'canceled']);
        Route::get('/trips/search', [PassengerTripController::class, 'search']);
        Route::get('/trips/{id}', [PassengerTripController::class, 'show'])->whereNumber('id');
        Route::get('/trip-categories', [PassengerTripController::class, 'categories']);
        Route::get('/trip-categories/{governorateId}/trips', [PassengerTripController::class, 'categoryTrips'])->whereNumber('governorateId');
        Route::get('/bookings', [BookingController::class, 'index']);
        Route::get('/trips/{tripId}/bookings', [BookingController::class, 'byTrip'])->whereNumber('tripId');
        Route::get('/bookings/{id}', [BookingController::class, 'show'])->whereNumber('id');
        Route::post('/bookings', [BookingController::class, 'store']);
        Route::post('/bookings/{id}/cancel', [BookingController::class, 'cancel'])->whereNumber('id');
        Route::get('/trips/{id}/tracking', [BookingController::class, 'tracking'])->whereNumber('id');
        Route::post('/rate-trip/{tripId}', [PassengerRatingController::class, 'store'])->whereNumber('tripId');
        
        // Complaints
        Route::post('/complaints', [ComplaintController::class, 'store']);
        Route::get('/complaints', [PassengerComplaintController::class, 'index']);
        
       //receipts
        Route::get('/receipts', [PassengerReceiptController::class, 'index']);
        Route::get('/receipts/{id}', [PassengerReceiptController::class, 'show'])->whereNumber('id');
    
        //wallet
        Route::get('/wallet', [PassengerWalletController::class, 'show']);
        Route::get('/wallet/transactions', [PassengerWalletController::class, 'transactions']);
        Route::get('/wallet/transactions/{id}', [PassengerWalletController::class, 'showTransaction'])->whereNumber('id');

        // profile
        Route::get('/me', [PassengerProfileController::class, 'show']);
        Route::patch('/me', [PassengerProfileController::class, 'update']);
        Route::get('/users/{id}', [PassengerProfileController::class, 'showOtherPassenger'])->whereNumber('id');
        Route::get('/drivers/{driverId}', [PassengerProfileController::class, 'showDriverProfile'])->whereNumber('driverId');
    
        });
    
});
Route::prefix('v1/driver')->group(function () {
    Route::post('/login', [DriverAuthController::class, 'login'])->middleware('throttle:login');
    
    Route::middleware(['auth:sanctum', 'active', 'role:driver'])->group(function(){
       
        //trips
        Route::post('/trips/preview', [TripController::class, 'preview']);
        Route::post('/trips', [TripController::class, 'store']);
        Route::get('/trips', [TripController::class, 'index']);
        Route::get('/trips/pending', [TripController::class, 'pending']);
        Route::get('/trips/current', [TripController::class, 'current']);
        Route::get('/trips/completed', [TripController::class, 'completed']);
        Route::get('/trips/canceled', [TripController::class, 'canceled']);
        Route::get('/trips/{id}/tracking', [TripController::class, 'tracking'])->whereNumber('id');
        Route::post('/trips/{id}/location', [TripController::class, 'storeLocation'])->whereNumber('id');
        Route::get('/trips/{id}', [TripController::class, 'show'])->whereNumber('id');
        Route::post('/trips/{id}/start', [TripController::class, 'start'])->whereNumber('id');
        Route::post('/trips/{id}/cancel', [TripController::class, 'cancel'])->whereNumber('id');
        Route::post('/trips/{id}/complete', [TripController::class, 'complete'])->whereNumber('id');
    
        
        //bookings
        Route::get('/bookings', [TripController::class, 'bookings']);
        Route::get('/bookings/{id}', [TripController::class, 'showBooking'])->whereNumber('id');
        Route::patch('/bookings/{id}/status', [TripController::class, 'updateBookingStatus'])->whereNumber('id');
        Route::patch('/bookings/{id}/attendance', [TripController::class, 'updateBookingAttendance'])->whereNumber('id');
        Route::get('/trips/{id}/bookings', [TripController::class, 'tripBookings'])->whereNumber('id');
        Route::get('/trips/{id}/attendance', [TripController::class, 'attendance'])->whereNumber('id');
        
        // Complaints
        Route::post('/complaints', [ComplaintController::class, 'store']);
        Route::get('/complaints', [DriverComplaintController::class, 'index']);
        // ratings
        Route::get('/rating', [DriverRatingController::class, 'show']);
        Route::get('/reports/financial', [DriverFinancialReportController::class, 'show']);
        
        //receipts
        Route::get('/receipts', [DriverReceiptController::class, 'index']);
        Route::get('/receipts/{id}', [DriverReceiptController::class, 'show'])->whereNumber('id');
        //wallet
        Route::get('/wallet', [DriverWalletController::class, 'show']);
        Route::get('/wallet/transactions', [DriverWalletController::class, 'transactions']);
        Route::get('/wallet/transactions/{id}', [DriverWalletController::class, 'showTransaction'])->whereNumber('id');

        // profile
        Route::get('/me', [DriverProfileController::class, 'show']);
        Route::get('/passengers/{id}', [DriverProfileController::class, 'showPassengerProfile'])->whereNumber('id');
        
    });
    
});
