<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponse;
use App\Services\TripStatusService;
use Throwable;

class TripStatusController extends Controller
{
    public function __construct(
        private readonly TripStatusService $tripStatusService
    ) {
    }

    public function index()
    {
        try {
            return ApiResponse::success(
                'تم جلب حالات الرحلة بنجاح.',
                200,
                $this->tripStatusService->list()
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب حالات الرحلة.', 500);
        }
    }
}
