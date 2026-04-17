<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passenger\ListWalletTransactionsRequest;
use App\Http\Resources\ApiResponse;
use App\Services\PassengerWalletOverviewService;
use RuntimeException;
use Throwable;

class WalletController extends Controller
{
    public function __construct(
        protected PassengerWalletOverviewService $passengerWalletOverviewService
    ) {
    }

    public function show()
    {
        try {
            return ApiResponse::success(
                'تم جلب معلومات محفظة الراكب بنجاح.',
                200,
                $this->passengerWalletOverviewService->getWalletOverview(request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب معلومات المحفظة.', 500);
        }
    }

    public function transactions(ListWalletTransactionsRequest $request)
    {
        try {
            return ApiResponse::success(
                'تم جلب العمليات المالية بنجاح.',
                200,
                $this->passengerWalletOverviewService->listTransactions(
                    $request->user(),
                    $request->validated()
                )
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب العمليات المالية.', 500);
        }
    }

    public function showTransaction(int $id)
    {
        try {
            return ApiResponse::success(
                'تم جلب تفاصيل العملية بنجاح.',
                200,
                $this->passengerWalletOverviewService->showTransactionDetails($id, request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب تفاصيل العملية.', 500);
        }
    }
}
