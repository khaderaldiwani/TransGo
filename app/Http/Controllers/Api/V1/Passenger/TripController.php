<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passenger\SearchTripsRequest;
use App\Http\Resources\ApiResponse;
use App\Services\PassengerTripHistoryService;
use App\Services\PassengerTripSearchService;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class TripController extends Controller
{
    public function __construct(
        private readonly PassengerTripHistoryService $passengerTripHistoryService,
        private readonly PassengerTripSearchService $passengerTripSearchService
    ) {
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

    public function search(SearchTripsRequest $request)
    {
        try {
            return ApiResponse::success(
                'تم جلب الرحلات المطابقة بنجاح.',
                200,
                $this->passengerTripSearchService->search($request->validated())
            );
        } catch (ValidationException $e) {
            return ApiResponse::validation('Validation failed.', $e->errors(), 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء البحث عن الرحلات.', 500);
        }
    }
}
