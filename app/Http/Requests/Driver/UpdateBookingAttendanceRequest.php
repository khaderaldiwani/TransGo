<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBookingAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attendance_status' => ['required', 'in:present,absent'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
