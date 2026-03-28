<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDriverRequest;
use App\Http\Resources\ApiResponse;
use App\Services\DriverManagementService;
use RuntimeException;
use Throwable;

class DriverController extends Controller
{
    public function __construct(protected DriverManagementService $driverManagementService)
    {
    }

    public function store(StoreDriverRequest $request)
    {
        try {
            $result = $this->driverManagementService->createDriver(
                $request->validated(),
                $request->user()
            );

            return ApiResponse::success('تم إنشاء السائق بنجاح.', 201, $result);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }
}
