<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

class StoreTripLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'speed_kmh' => ['nullable', 'numeric', 'min:0', 'max:400'],
            'heading' => ['nullable', 'numeric', 'min:0', 'max:360'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'recorded_at' => ['nullable', 'date'],
        ];
    }
}
