<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponse;
use App\Services\VehicleCategoryService;
use Throwable;

class VehicleCategoryController extends Controller
{
    public function __construct(private readonly VehicleCategoryService $vehicleCategoryService)
    {
    }

    public function index()
    {
        try {
            return ApiResponse::success(
                'تم جلب تصنيفات السيارات بنجاح.',
                200,
                ['items' => $this->vehicleCategoryService->activePassengerList()]
            );
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب تصنيفات السيارات.', 500);
        }
    }
}
