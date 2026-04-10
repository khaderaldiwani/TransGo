<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDriverRequest;
use App\Http\Resources\ApiResponse;
use App\Services\DriverManagementService;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class DriverController extends Controller
{
    public function __construct(protected DriverManagementService $driverManagementService)
    {
    }

    public function index(Request $request)
    {
        try {
            $drivers = $this->driverManagementService->listDrivers($request->query());
            return ApiResponse::success('تم جلب قائمة السائقين بنجاح.', 200, $drivers);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }

    public function show(int $id)
    {
        try {
            $driver = $this->driverManagementService->getDriver($id);
            return ApiResponse::success('تم جلب بيانات السائق بنجاح.', 200, $driver);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
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

    public function toggleStatus(int $id, Request $request)
    {
        try {
            $driver = $this->driverManagementService->toggleStatus($id, $request->user());
            return ApiResponse::success('تم تغيير حالة الحساب بنجاح.', 200, $driver);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }
}
