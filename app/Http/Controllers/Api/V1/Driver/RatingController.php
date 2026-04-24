<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponse;
use App\Services\TripRatingService;
use RuntimeException;
use Throwable;

class RatingController extends Controller
{
    public function __construct(private readonly TripRatingService $tripRatingService)
    {
    }

    public function show()
    {
        try {
            return ApiResponse::success(
                'Driver rating analytics retrieved successfully.',
                200,
                $this->tripRatingService->getDriverRatingSummary(request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('Unexpected error while retrieving driver rating analytics.', 500);
        }
    }
}
