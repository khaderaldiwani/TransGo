<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitComplaintRequest;
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

    public function store(SubmitComplaintRequest $request): JsonResponse
    {
        try {
            $complaint = $this->complaintService->submitComplaint($request->validated(), $request->user());

            return ApiResponse::success(
                'تم تقديم الشكوى بنجاح.',
                201,
                [
                    'complaint_id' => $complaint->complaint_id,
                    'complaint_code' => $complaint->complaint_code,
                    'status' => $complaint->status,
                    'created_at' => $complaint->created_at?->toIso8601String(),
                ]
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 422);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء تقديم الشكوى.', 500);
        }
    }
}