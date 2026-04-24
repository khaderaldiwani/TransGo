<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponse;
use App\Services\UserComplaintHistoryService;
use RuntimeException;
use Throwable;

class ComplaintController extends Controller
{
    public function __construct(private readonly UserComplaintHistoryService $userComplaintHistoryService)
    {
    }

    public function index()
    {
        try {
            return ApiResponse::success(
                'Driver complaints retrieved successfully.',
                200,
                $this->userComplaintHistoryService->listForUser(request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('Unexpected error while retrieving driver complaints.', 500);
        }
    }
}
