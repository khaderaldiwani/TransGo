<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponse;
use App\Services\BookingStatusService;
use Throwable;

class BookingStatusController extends Controller
{
    public function __construct(
        private readonly BookingStatusService $bookingStatusService
    ) {
    }

    public function index()
    {
        try {
            return ApiResponse::success(
                'تم جلب حالات الحجز بنجاح.',
                200,
                $this->bookingStatusService->list()
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب حالات الحجز.', 500);
        }
    }
}
