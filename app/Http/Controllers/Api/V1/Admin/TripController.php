<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelTripRequest;
use App\Http\Requests\Admin\TripFilterRequest;
use App\Http\Requests\Admin\TripTrackingHistoryRequest;
use App\Http\Resources\ApiResponse;
use App\Services\AdminTripManagementService;
use RuntimeException;
use Throwable;

class TripController extends Controller
{
    public function __construct(
        protected AdminTripManagementService $tripManagementService
    ) {
    }

    public function index(TripFilterRequest $request)
    {
        try {
            return ApiResponse::success(
                'تم جلب قائمة الرحلات بنجاح.',
                200,
                $this->tripManagementService->listTrips($request->validated())
            );
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب الرحلات.', 500);
        }
    }

    public function show(int $id)
    {
        try {
            return ApiResponse::success(
                'تم جلب تفاصيل الرحلة بنجاح.',
                200,
                $this->tripManagementService->getTripDetails($id)
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب تفاصيل الرحلة.', 500);
        }
    }

    public function activeTracking(TripFilterRequest $request)
    {
        try {
            return ApiResponse::success(
                'تم جلب بيانات التتبع المباشر للرحلات النشطة بنجاح.',
                200,
                $this->tripManagementService->activeTracking($request->validated())
            );
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب بيانات التتبع.', 500);
        }
    }

    public function tracking(TripTrackingHistoryRequest $request, int $id)
    {
        try {
            return ApiResponse::success(
                'تم جلب مسار تتبع الرحلة بنجاح.',
                200,
                $this->tripManagementService->getTripTracking(
                    $id,
                    (int) ($request->validated()['history_limit'] ?? 200)
                )
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب مسار التتبع.', 500);
        }
    }

    public function delayed(TripFilterRequest $request)
    {
        try {
            return ApiResponse::success(
                'تم جلب الرحلات المتأخرة بنجاح.',
                200,
                $this->tripManagementService->delayedTrips($request->validated())
            );
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب الرحلات المتأخرة.', 500);
        }
    }

    public function cancel(CancelTripRequest $request, int $id)
    {
        try {
            return ApiResponse::success(
                'تم إلغاء الرحلة وإشعار الأطراف المعنية بنجاح.',
                200,
                $this->tripManagementService->cancelTrip(
                    $id,
                    $request->validated('reason'),
                    $request->user()
                )
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء إلغاء الرحلة.', 500);
        }
    }
}
