<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PassengerWalletTopUpFilterRequest;
use App\Http\Requests\Admin\TopUpPassengerWalletRequest;
use App\Http\Resources\ApiResponse;
use App\Services\PassengerManagementService;
use App\Services\PassengerWalletService;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class PassengerController extends Controller
{
    public function __construct(
        protected PassengerManagementService $passengerManagementService,
        protected PassengerWalletService $passengerWalletService
    ) {
    }

    public function index(Request $request)
    {
        try {
            $passengers = $this->passengerManagementService->listPassengers($request->query());
            return ApiResponse::success('تم جلب قائمة المسافرين بنجاح.', 200, $passengers);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }

    public function show(int $id)
    {
        try {
            $passenger = $this->passengerManagementService->getPassenger($id);
            return ApiResponse::success('تم جلب بيانات المسافر بنجاح.', 200, $passenger);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }

    public function toggleStatus(int $id, Request $request)
    {
        try {
            $passenger = $this->passengerManagementService->toggleStatus($id, $request->user());
            return ApiResponse::success('تم تغيير حالة الحساب بنجاح.', 200, $passenger);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }

    public function topUpWallet(int $id, TopUpPassengerWalletRequest $request)
    {
        try {
            $result = $this->passengerWalletService->topUp($id, $request->validated(), $request->user());

            return ApiResponse::success('تم شحن محفظة الراكب بنجاح.', 200, $result);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }

    public function walletTopUps(PassengerWalletTopUpFilterRequest $request)
    {
        try {
            $transactions = $this->passengerWalletService->listTopUps($request->validated());

            return ApiResponse::success('تم جلب سجل شحن محافظ الركاب بنجاح.', 200, $transactions);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }
}
