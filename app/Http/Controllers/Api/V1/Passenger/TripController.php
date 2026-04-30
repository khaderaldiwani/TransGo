<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passenger\SearchTripsRequest;
use App\Http\Resources\ApiResponse;
use App\Services\PassengerTripCategoryService;
use App\Services\PassengerTripHistoryService;
use App\Services\PassengerTripDetailsService;
use App\Services\PassengerTripSearchService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class TripController extends Controller
{
    public function __construct(
        private readonly PassengerTripHistoryService $passengerTripHistoryService,
        private readonly PassengerTripDetailsService $passengerTripDetailsService,
        private readonly PassengerTripSearchService $passengerTripSearchService,
        private readonly PassengerTripCategoryService $passengerTripCategoryService
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

    public function current()
    {
        try {
            return ApiResponse::success(
                'تم جلب الرحلات الحالية بنجاح.',
                200,
                $this->passengerTripHistoryService->current(request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب الرحلات الحالية.', 500);
        }
    }

    public function pending()
    {
        try {
            return ApiResponse::success(
                'تم جلب الرحلات قيد الانتظار بنجاح.',
                200,
                $this->passengerTripHistoryService->pending(request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب الرحلات قيد الانتظار.', 500);
        }
    }

    public function completed()
    {
        try {
            return ApiResponse::success(
                'تم جلب الرحلات المكتملة بنجاح.',
                200,
                $this->passengerTripHistoryService->completed(request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب الرحلات المكتملة.', 500);
        }
    }

    public function canceled()
    {
        try {
            return ApiResponse::success(
                'تم جلب الرحلات الملغاة بنجاح.',
                200,
                $this->passengerTripHistoryService->canceled(request()->user())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب الرحلات الملغاة.', 500);
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

    public function show(int $id)
    {
        try {
            return ApiResponse::success(
                'تم جلب تفاصيل الرحلة بنجاح.',
                200,
                $this->passengerTripDetailsService->show($id, request()->query('trip_type'))
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب تفاصيل الرحلة.', 500);
        }
    }

    public function categories()
    {
        try {
            return ApiResponse::success(
                'تم جلب تصنيفات المحافظات بنجاح.',
                200,
                $this->passengerTripCategoryService->categories($this->categoryFilters())
            );
        } catch (ValidationException $e) {
            return ApiResponse::validation('Validation failed.', $e->errors(), 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب تصنيفات المحافظات.', 500);
        }
    }

    public function categoryTrips(int $governorateId)
    {
        try {
            return ApiResponse::success(
                'تم جلب رحلات التصنيف بنجاح.',
                200,
                $this->passengerTripCategoryService->trips($governorateId, $this->categoryFilters(true))
            );
        } catch (ValidationException $e) {
            return ApiResponse::validation('Validation failed.', $e->errors(), 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب رحلات التصنيف.', 500);
        }
    }

    private function categoryFilters(bool $withPagination = false): array
    {
        $rules = [
            'start_governorate_id' => ['nullable', 'integer', 'exists:governorates,governorate_id'],
            'departure_date' => ['nullable', 'date'],
            'trip_type' => ['nullable', 'string', 'in:shared,private'],
        ];

        if ($withPagination) {
            $rules['per_page'] = ['nullable', 'integer', 'min:1', 'max:100'];
            $rules['pickup_latitude'] = ['nullable', 'numeric', 'between:-90,90', 'required_with:pickup_longitude'];
            $rules['pickup_longitude'] = ['nullable', 'numeric', 'between:-180,180', 'required_with:pickup_latitude'];
            $rules['dropoff_latitude'] = ['nullable', 'numeric', 'between:-90,90', 'required_with:dropoff_longitude'];
            $rules['dropoff_longitude'] = ['nullable', 'numeric', 'between:-180,180', 'required_with:dropoff_latitude'];
        }

        return Validator::make(request()->query(), $rules)->validate();
    }
}
