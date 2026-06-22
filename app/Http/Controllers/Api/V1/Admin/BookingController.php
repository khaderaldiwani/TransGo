<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BookingFilterRequest;
use App\Http\Requests\Admin\BookingStatusUpdateRequest;
use App\Http\Resources\ApiResponse;
use App\Services\AdminBookingManagementService;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

class BookingController extends Controller
{
    public function __construct(protected AdminBookingManagementService $bookingService)
    {
    }

    public function index(BookingFilterRequest $request) // get all bookings with filters
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
    public function show(int $bookingId): JsonResponse
    {
        try {
            return ApiResponse::success(
                'تم جلب تفاصيل الحجز بنجاح.',
                200,
                $this->bookingService->getBookingDetails($bookingId)
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error('الحجز غير موجود.', 404);
        } catch (\Exception $e) {
            return ApiResponse::error('حدث خطأ أثناء جلب تفاصيل الحجز.', 500);
        }
    }

    public function updateStatus(BookingStatusUpdateRequest $request, int $bookingId): JsonResponse
    {
        try {
            return ApiResponse::success(
                'تم تحديث حالة الحجز بنجاح.',
                200,
                $this->bookingService->updateBookingStatus(
                    $bookingId,
                    $request->validated('status'),
                    $request->validated('reason'),
                    $request->user()
                )
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error('الحجز غير موجود.', 404);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 422);
        } catch (\Exception $e) {
            return ApiResponse::error('حدث خطأ أثناء تحديث حالة الحجز.', 500);
        }
    }
}
