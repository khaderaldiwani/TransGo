<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponse;
use App\Services\GovernorateService;
use Throwable;

class GovernorateController extends Controller
{
    public function __construct(
        private readonly GovernorateService $governorateService
    ) {
    }

    public function index()
    {
        try {
            return ApiResponse::success(
                'تم جلب المحافظات بنجاح.',
                200,
                $this->governorateService->list()
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب المحافظات.', 500);
        }
    }
}
