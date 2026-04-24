<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passenger\RateTripRequest;
use App\Http\Resources\ApiResponse;
use App\Services\TripRatingService;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class RatingController extends Controller
{
    public function __construct(private readonly TripRatingService $tripRatingService)
    {
    }

    public function store(RateTripRequest $request, int $tripId)
    {
        try {
            return ApiResponse::success(
                'Trip rated successfully.',
                201,
                $this->tripRatingService->rateTrip($tripId, $request->user(), $request->validated())
            );
        } catch (ValidationException $e) {
            return ApiResponse::validation('Validation failed.', $e->errors(), 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('Unexpected error while submitting trip rating.', 500);
        }
    }
}
