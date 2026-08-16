<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RatingFilterRequest;
use App\Http\Resources\ApiResponse;
use App\Models\Role;
use App\Models\User;
use App\Services\TripRatingService;
use RuntimeException;
use Throwable;

class RatingController extends Controller
{
    public function __construct(private readonly TripRatingService $tripRatingService)
    {
    }

    public function index(RatingFilterRequest $request)
    {
        try {
            return ApiResponse::success(
                'Ratings retrieved successfully.',
                200,
                $this->tripRatingService->getAdminRatings($request->validated())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('Unexpected error while retrieving ratings.', 500);
        }
    }

    public function listRatings(RatingFilterRequest $request)
    {
        try {
            return ApiResponse::success(
                'Rating list retrieved successfully.',
                200,
                $this->tripRatingService->getAdminRatingsList($request->validated())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('Unexpected error while retrieving rating list.', 500);
        }
    }

    public function show(int $ratingId)
    {
        try {
            return ApiResponse::success(
                'Rating retrieved successfully.',
                200,
                $this->tripRatingService->getAdminRatingById($ratingId)
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error('Rating not found.', 404);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('Unexpected error while retrieving rating.', 500);
        }
    }

    public function hide(int $ratingId)
    {
        try {
            return ApiResponse::success(
                'Rating visibility updated successfully.',
                200,
                $this->tripRatingService->toggleRatingVisibility($ratingId, request()->user())
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error('Rating not found.', 404);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('Unexpected error while updating rating visibility.', 500);
        }
    }

    public function lowRatedDrivers()
    {
        try {
            return ApiResponse::success(
                'Low-rated drivers retrieved successfully.',
                200,
                $this->tripRatingService->getLowRatedDrivers()
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('Unexpected error while retrieving low-rated drivers.', 500);
        }
    }

    public function driverRating(int $driverId)
    {
        try {
            $driver = User::query()
                ->whereHas('roles', fn ($query) => $query->where('name', Role::ROLE_DRIVER))
                ->findOrFail($driverId);

            return ApiResponse::success(
                'Driver rating retrieved successfully.',
                200,
                $this->tripRatingService->getDriverRatingSummary($driver)
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error('Driver not found.', 404);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('Unexpected error while retrieving driver rating.', 500);
        }
    }
}
