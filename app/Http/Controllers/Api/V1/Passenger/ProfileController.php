<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passenger\UpdateProfileRequest;
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
        protected PassengerManagementService $passengerManagementService,
        protected DriverManagementService $driverManagementService
    ) {
    }

    public function show(Request $request)
    {
        try {
            $profile = $this->passengerManagementService->getOwnProfile($request->user());
            return ApiResponse::success('Passenger profile retrieved successfully.', 200, new PassengerProfileResource($profile));
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('Unexpected error while retrieving passenger profile.', 500);
        }
    }

    public function update(UpdateProfileRequest $request)
    {
        try {
            $profile = $this->passengerManagementService->updateProfile($request->user(), $request->validated());
            return ApiResponse::success('Passenger profile updated successfully.', 200, new PassengerProfileResource($profile));
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('Unexpected error while updating passenger profile.', 500);
        }
    }

    public function showOtherPassenger(int $id)
    {
        try {
            $profile = $this->passengerManagementService->getOtherPassengerProfile($id, false, false);
            return ApiResponse::success('Passenger public profile retrieved successfully.', 200, new PassengerProfileResource($profile));
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('Unexpected error while retrieving passenger public profile.', 500);
        }
    }

    public function showDriverProfile(int $driverId, Request $request)
    {
        try {
            $perPage = (int) $request->query('per_page', 10);
            $result = $this->driverManagementService->getDriverProfileForPassenger($driverId, $perPage);

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
}
