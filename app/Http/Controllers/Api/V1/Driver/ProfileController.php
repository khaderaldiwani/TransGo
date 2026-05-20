<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponse;
use App\Http\Resources\DriverProfileResource;
use App\Http\Resources\DriverReviewResource;
use App\Http\Resources\PassengerProfileResource;
use App\Services\DriverManagementService;
use App\Services\PassengerManagementService;
use Illuminate\Http\Request;
use Throwable;
use RuntimeException;

class ProfileController extends Controller
{
    public function __construct(
        protected DriverManagementService $driverManagementService,
        protected PassengerManagementService $passengerManagementService
    ) {
    }

    public function show(Request $request)
    {
        try {
            $perPage = (int) $request->query('per_page', 10);
            $result = $this->driverManagementService->getAuthenticatedDriverProfile($request->user(), $perPage);

            return ApiResponse::success('Driver profile retrieved successfully.', 200, [
                'profile' => new DriverProfileResource($result['profile']),
                'reviews' => DriverReviewResource::collection($result['reviews']),
            ]);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('Unexpected error while retrieving driver profile.', 500);
        }
    }

    public function showPassengerProfile(int $id)
    {
        try {
            $profile = $this->passengerManagementService->getOtherPassengerProfile($id, false, true);
            return ApiResponse::success('Passenger profile retrieved successfully.', 200, new PassengerProfileResource($profile));
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('Unexpected error while retrieving passenger profile.', 500);
        }
    }
}
