<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BookingFilterRequest;
use App\Http\Resources\ApiResponse;
use App\Services\AdminBookingManagementService;
use Throwable;

class BookingController extends Controller
{
    public function __construct(protected AdminBookingManagementService $bookingService)
    {
    }

    public function index(BookingFilterRequest $request)
    {
        try {
            return ApiResponse::success(
                'تم جلب الحجوزات بنجاح.',
                200,
                $this->bookingService->listBookings($request->validated())
            );
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب الحجوزات.', 500);
        }
    }
}
