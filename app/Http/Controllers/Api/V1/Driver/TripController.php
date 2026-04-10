<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\PreviewTripRequest;
use App\Http\Requests\Driver\StoreTripRequest;
use App\Http\Resources\ApiResponse;
use App\Services\TripPreviewService;
use App\Services\TripService;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class TripController extends Controller
{
    public function __construct(
        protected TripService $tripService,
        protected TripPreviewService $tripPreviewService
    ) {
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
}
