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
            'start_governorate_id' => ['required', 'integer', 'exists:governorates,governorate_id'],
            'end_governorate_id' => ['required', 'integer', 'exists:governorates,governorate_id'],
            'departure_date' => ['required', 'date'],
            'trip_type' => ['required', 'string', Rule::in(['shared', 'private'])],
            'seats_required' => ['nullable', 'integer', 'min:1'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::validation('Validation failed.', $validator->errors(), 422)
        );
    }
}
