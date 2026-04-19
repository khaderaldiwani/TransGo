<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ComplaintFilterRequest;
use App\Http\Requests\Admin\ComplaintStatusUpdateRequest;
use App\Http\Resources\ApiResponse;
use App\Services\ComplaintService;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

class ComplaintController extends Controller
{
    public function __construct(protected ComplaintService $complaintService)
    {
    }

    public function index(ComplaintFilterRequest $request): JsonResponse
    {
        try {
            return ApiResponse::success(
                'تم جلب الشكاوى بنجاح.',
                200,
                $this->complaintService->listComplaints($request->validated())
            );
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب الشكاوى.', 500);
        }
    }

    public function show(int $complaintId): JsonResponse
    {
        try {
            return ApiResponse::success(
                'تم جلب تفاصيل الشكوى بنجاح.',
                200,
                $this->complaintService->getComplaintDetails($complaintId)
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error('الشكوى غير موجودة.', 404);
        } catch (\Exception $e) {
            return ApiResponse::error('حدث خطأ أثناء جلب تفاصيل الشكوى.', 500);
        }
    }

    public function updateStatus(ComplaintStatusUpdateRequest $request, int $complaintId): JsonResponse
    {
        try {
            return ApiResponse::success(
                'تم تحديث حالة الشكوى بنجاح.',
                200,
                $this->complaintService->updateComplaintStatus(
                    $complaintId,
                    $request->validated('status'),
                    $request->validated('notes'),
                    $request->user()
                )
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error('الشكوى غير موجودة.', 404);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 422);
        } catch (\Exception $e) {
            return ApiResponse::error('حدث خطأ أثناء تحديث حالة الشكوى.', 500);
        }
    }

    public function auditTrail(int $complaintId): JsonResponse
    {
        try {
            return ApiResponse::success(
                'تم جلب سجل النشاطات بنجاح.',
                200,
                $this->complaintService->getComplaintAuditTrail($complaintId)
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error('الشكوى غير موجودة.', 404);
        } catch (\Exception $e) {
            return ApiResponse::error('حدث خطأ أثناء جلب سجل النشاطات.', 500);
        }
    }
}