<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListCommissionRatesRequest;
use App\Http\Requests\Admin\StoreCommissionRateRequest;
use App\Http\Resources\ApiResponse;
use App\Services\CommissionRateService;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CommissionRateController extends Controller
{
    public function __construct(
        protected CommissionRateService $commissionRateService
    ) {
    }

    public function index(ListCommissionRatesRequest $request)
    {
        try {
            return ApiResponse::success(
                'تم جلب سجل نسب العمولة بنجاح.',
                200,
                $this->commissionRateService->listRates($request->validated())
            );
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب سجل نسب العمولة.', 500);
        }
    }

    public function current()
    {
        try {
            return ApiResponse::success(
                'تم جلب نسبة العمولة الحالية بنجاح.',
                200,
                $this->commissionRateService->currentRate()
            );
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب نسبة العمولة الحالية.', 500);
        }
    }

    public function store(StoreCommissionRateRequest $request)
    {
        try {
            return ApiResponse::success(
                'تم تحديث نسبة العمولة بنجاح.',
                201,
                $this->commissionRateService->createRate(
                    $request->validated(),
                    $request->user()
                )
            );
        } catch (ValidationException $e) {
            return ApiResponse::validation('Validation failed.', $e->errors(), 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء تحديث نسبة العمولة.', 500);
        }
    }
}
