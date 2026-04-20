<?php

namespace App\Http\Requests\Passenger;

use Illuminate\Foundation\Http\FormRequest;

class TripTrackingQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'history_limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }
}
