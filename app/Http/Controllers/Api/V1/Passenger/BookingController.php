<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passenger\CancelBookingRequest;
use App\Http\Requests\Passenger\CreateBookingRequest;
use App\Http\Requests\Passenger\TripTrackingQueryRequest;
use App\Http\Resources\ApiResponse;
use App\Services\BookingService;
use App\Services\PassengerBookingOverviewService;
use App\Services\PassengerTripTrackingService;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class BookingController extends Controller
{
    public function __construct(
        protected BookingService $bookingService,
        protected PassengerTripTrackingService $passengerTripTrackingService,
        protected PassengerBookingOverviewService $passengerBookingOverviewService
    ) {
    }

    public function index()
    {
        try {
            return ApiResponse::success(
                'تم جلب الحجوزات مجمعة حسب الرحلة بنجاح.',
                200,
                $this->passengerBookingOverviewService->groupedByTrip(request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب الحجوزات.', 500);
        }
    }

    public function show(int $id)
    {
        try {
            return ApiResponse::success(
                'تم جلب تفاصيل الحجز بنجاح.',
                200,
                $this->passengerBookingOverviewService->show($id, request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب تفاصيل الحجز.', 500);
        }
    }

    public function byTrip(int $tripId)
    {
        try {
            return ApiResponse::success(
                'تم جلب حجوزات الرحلة بنجاح.',
                200,
                $this->passengerBookingOverviewService->listForTrip($tripId, request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب حجوزات الرحلة.', 500);
        }
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

    public function tracking(TripTrackingQueryRequest $request, int $id)
    {
        try {
            return ApiResponse::success(
                'تم جلب بيانات تتبع الرحلة للراكب بنجاح.',
                200,
                $this->passengerTripTrackingService->getTripTracking(
                    $id,
                    $request->user(),
                    (int) ($request->validated()['history_limit'] ?? 100)
                )
            );
        } catch (ValidationException $e) {
            return ApiResponse::validation('خطأ في صحة البيانات.', $e->errors(), 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }
}
