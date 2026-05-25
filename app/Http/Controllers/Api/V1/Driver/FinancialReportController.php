<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\FinancialReportRequest;
use App\Http\Resources\ApiResponse;
use App\Services\DriverFinancialReportService;
use Throwable;

class FinancialReportController extends Controller
{
    public function __construct(
        private readonly DriverFinancialReportService $driverFinancialReportService
    ) {
    }

    public function show(FinancialReportRequest $request)
    {
        try {
            return ApiResponse::success(
                'تم جلب التقرير المالي للسائق بنجاح.',
                200,
                $this->driverFinancialReportService->generate($request->user(), $request->validated())
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب التقرير المالي للسائق.', 500);
        }
    }
}
