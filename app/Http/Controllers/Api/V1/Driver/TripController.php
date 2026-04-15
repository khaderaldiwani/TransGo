<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\CancelTripRequest;
use App\Http\Requests\Driver\PreviewTripRequest;
use App\Http\Requests\Driver\StoreTripRequest;
use App\Http\Resources\ApiResponse;
use App\Services\DriverTripManagementService;
use App\Services\TripPreviewService;
use App\Services\TripService;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class TripController extends Controller
{
    public function __construct(
        protected TripService $tripService,
        protected TripPreviewService $tripPreviewService,
        protected DriverTripManagementService $driverTripManagementService
    ) {
    }

    public function index()
    {
        try {
            return ApiResponse::success(
                'تم جلب جميع رحلات السائق بنجاح.',
                200,
                $this->driverTripManagementService->listTrips(request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب الرحلات.', 500);
        }
    }

    public function pending()
    {
        try {
            return ApiResponse::success(
                'تم جلب الرحلات قيد الانتظار بنجاح.',
                200,
                $this->driverTripManagementService->listPendingTrips(request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب الرحلات قيد الانتظار.', 500);
        }
    }

    public function current()
    {
        try {
            return ApiResponse::success(
                'تم جلب الرحلات الحالية بنجاح.',
                200,
                $this->driverTripManagementService->listCurrentTrips(request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب الرحلات الحالية.', 500);
        }
    }

    public function completed()
    {
        try {
            return ApiResponse::success(
                'تم جلب الرحلات المنجزة بنجاح.',
                200,
                $this->driverTripManagementService->listCompletedTrips(request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب الرحلات المنجزة.', 500);
        }
    }

    public function canceled()
    {
        try {
            return ApiResponse::success(
                'تم جلب الرحلات الملغاة بنجاح.',
                200,
                $this->driverTripManagementService->listCanceledTrips(request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب الرحلات الملغاة.', 500);
        }
    }

    public function show(int $id)
    {
        try {
            return ApiResponse::success(
                'تم جلب تفاصيل الرحلة بنجاح.',
                200,
                $this->driverTripManagementService->showTripDetails($id, request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب تفاصيل الرحلة.', 500);
        }
    }

    public function attendance(int $id)
    {
        try {
            return ApiResponse::success(
                'تم جلب بيانات الحضور والغياب بنجاح.',
                200,
                $this->driverTripManagementService->showTripAttendance($id, request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب بيانات الحضور والغياب.', 500);
        }
    }

    public function preview(PreviewTripRequest $request)
    {
        try {
            $preview = $this->tripPreviewService->preview(
                $request->validated(),
                $request->user()
            );

            return ApiResponse::success('تمت معاينة الرحلة بنجاح.', 200, $preview);
        } catch (ValidationException $e) {
            return ApiResponse::validation('Validation failed.', $e->errors(), 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء معاينة الرحلة.', 500);
        }
    }

    public function store(StoreTripRequest $request)
    {
        try {
            $trip = $this->tripService->createTrip(
                $request->validated(),
                $request->user()
            );

            return ApiResponse::success('تم إنشاء الرحلة بنجاح.', 201, $trip);
        } catch (ValidationException $e) {
            return ApiResponse::validation('Validation failed.', $e->errors(), 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء إنشاء الرحلة.', 500);
        }
    }

    public function cancel(CancelTripRequest $request, int $id)
    {
        try {
            return ApiResponse::success(
                'تم إلغاء الرحلة والحجوزات المرتبطة بها بنجاح.',
                200,
                $this->driverTripManagementService->cancelTrip(
                    $id,
                    $request->user(),
                    $request->validated('reason')
                )
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء إلغاء الرحلة.', 500);
        }
    }
}
