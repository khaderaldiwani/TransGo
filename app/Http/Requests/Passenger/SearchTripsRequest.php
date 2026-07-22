<?php

namespace App\Http\Requests\Passenger;

use App\Http\Resources\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class SearchTripsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_governorate_id' => ['nullable', 'integer', 'exists:governorates,governorate_id'],
            'end_governorate_id' => ['nullable', 'integer', 'exists:governorates,governorate_id'],
            'departure_date' => ['required', 'date'],
            'trip_type' => ['required', 'string', Rule::in(['shared', 'private'])],
            'vehicle_category_id' => ['nullable', 'integer', 'exists:vehicle_categories,category_id'],
            'pickup_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:pickup_longitude'],
            'pickup_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:pickup_latitude'],
            'dropoff_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:dropoff_longitude'],
            'dropoff_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:dropoff_latitude'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasPickupPoint = $this->filled('pickup_latitude') && $this->filled('pickup_longitude');
            $hasDropoffPoint = $this->filled('dropoff_latitude') && $this->filled('dropoff_longitude');

            if (! $hasPickupPoint && ! $hasDropoffPoint) {
                if (! $this->filled('start_governorate_id')) {
                    $validator->errors()->add('start_governorate_id', 'محافظة الانطلاق مطلوبة عند البحث بدون نقطة صعود أو نزول.');
                }

                if (! $this->filled('end_governorate_id')) {
                    $validator->errors()->add('end_governorate_id', 'محافظة الوصول مطلوبة عند البحث بدون نقطة صعود أو نزول.');
                }
            }

            if ($hasPickupPoint && ! $this->filled('start_governorate_id')) {
                $validator->errors()->add('start_governorate_id', 'محافظة الانطلاق مطلوبة عند البحث بنقطة صعود.');
            }

            if ($hasDropoffPoint && ! $this->filled('end_governorate_id')) {
                $validator->errors()->add('end_governorate_id', 'محافظة الوصول مطلوبة عند البحث بنقطة نزول.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::validation('Validation failed.', $validator->errors(), 422)
        );
    }
}
