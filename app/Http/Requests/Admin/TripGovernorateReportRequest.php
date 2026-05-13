<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TripGovernorateReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'start_governorate_id' => ['nullable', 'integer', 'exists:governorates,governorate_id'],
            'end_governorate_id' => ['nullable', 'integer', 'exists:governorates,governorate_id'],
        ];
    }
}
