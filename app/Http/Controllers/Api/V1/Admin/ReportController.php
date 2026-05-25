<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DriverEarningsReportRequest;
use App\Http\Requests\Admin\RevenueReportRequest;
use App\Http\Requests\Admin\TripGovernorateReportRequest;
use App\Http\Resources\ApiResponse;
use App\Services\DriverEarningsReportService;
use App\Services\RevenueReportService;
use App\Services\TripGovernorateReportService;
use Throwable;

class ReportController extends Controller
{
    public function __construct(
        private readonly TripGovernorateReportService $tripGovernorateReportService,
        private readonly RevenueReportService $revenueReportService,
        private readonly DriverEarningsReportService $driverEarningsReportService
    ) {
    }

    public function tripsByGovernorates(TripGovernorateReportRequest $request)
    {
        try {
            return ApiResponse::success(
                'تم جلب تقرير نشاط الرحلات حسب المحافظات بنجاح.',
                200,
                $this->tripGovernorateReportService->generate($request->validated())
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب تقرير نشاط الرحلات حسب المحافظات.', 500);
        }
    }

    public function revenue(RevenueReportRequest $request)
    {
        try {
            return ApiResponse::success(
                'تم جلب تقرير الإيرادات بنجاح.',
                200,
                $this->revenueReportService->generate($request->validated())
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب تقرير الإيرادات.', 500);
        }
    }

    public function driverEarnings(DriverEarningsReportRequest $request)
    {
        try {
            return ApiResponse::success(
                'تم جلب تقرير أرباح السائقين بنجاح.',
                200,
                $this->driverEarningsReportService->generate($request->validated())
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب تقرير أرباح السائقين.', 500);
        }
    }
}
