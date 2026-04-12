<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditLogFilterRequest;
use App\Http\Resources\ApiResponse;
use App\Services\AuditLogService;
use Throwable;

class AuditLogController extends Controller
{
    public function __construct(protected AuditLogService $auditLogService)
    {
    }

    public function index(AuditLogFilterRequest $request)
    {
        try {
            $logs = $this->auditLogService->list($request->validated());

            return ApiResponse::success('تم جلب سجل النشاطات بنجاح.', 200, $logs);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }
}
