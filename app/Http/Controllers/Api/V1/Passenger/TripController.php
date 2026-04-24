<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponse;
use App\Services\PassengerTripHistoryService;
use RuntimeException;
use Throwable;

class TripController extends Controller
{
    public function __construct(private readonly PassengerTripHistoryService $passengerTripHistoryService)
    {
    }

    public function index()
    {
        try {
            return ApiResponse::success(
                'Passenger trips retrieved successfully.',
                200,
                $this->passengerTripHistoryService->listTrips(request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('Unexpected error while retrieving passenger trips.', 500);
        }
    }
}
