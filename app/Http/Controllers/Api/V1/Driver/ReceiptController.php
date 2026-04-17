<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\ListReceiptRequest;
use App\Http\Resources\ApiResponse;
use App\Services\ReceiptService;
use RuntimeException;
use Throwable;

class ReceiptController extends Controller
{
    public function __construct(protected ReceiptService $receiptService)
    {
    }

    public function index(ListReceiptRequest $request)
    {
        try {
            return ApiResponse::success(
                'تم جلب إيصالات السائق بنجاح.',
                200,
                $this->receiptService->listForUser($request->user(), $request->validated())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب الإيصالات.', 500);
        }
    }

    public function show(int $id)
    {
        try {
            return ApiResponse::success(
                'تم جلب تفاصيل الإيصال بنجاح.',
                200,
                $this->receiptService->showForUser($id, request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب تفاصيل الإيصال.', 500);
        }
    }
}
