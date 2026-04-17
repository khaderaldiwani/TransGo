<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passenger\CancelBookingRequest;
use App\Http\Requests\Passenger\CreateBookingRequest;
use App\Http\Resources\ApiResponse;
use App\Services\BookingService;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class BookingController extends Controller
{
    public function __construct(protected BookingService $bookingService)
    {
    }

    public function store(CreateBookingRequest $request)
    {
        try {
            $booking = $this->bookingService->createBooking($request->validated(), $request->user());

            return ApiResponse::success('تم إنشاء الحجز بنجاح.', 201, $booking);
        } catch (ValidationException $e) {
            return ApiResponse::validation('خطأ في صحة البيانات.', $e->errors(), 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }

    public function cancel(CancelBookingRequest $request, int $id)
    {
        try {
            $result = $this->bookingService->cancelBooking(
                $id,
                $request->user(),
                $request->validated('reason')
            );

            return ApiResponse::success('تم إلغاء الحجز بنجاح.', 200, $result);
        } catch (ValidationException $e) {
            return ApiResponse::validation('خطأ في صحة البيانات.', $e->errors(), 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }
}
