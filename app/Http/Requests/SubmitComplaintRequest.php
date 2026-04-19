<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'complaint_type' => ['required', 'string', Rule::in(['ride', 'driver', 'passenger', 'payment', 'technical'])],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'related_trip_id' => ['nullable', 'integer', 'exists:trips,trip_id'],
            'related_booking_id' => ['nullable', 'integer', 'exists:bookings,booking_id'],
            'related_driver_id' => ['nullable', 'integer', 'exists:users,user_id'],
            'related_passenger_id' => ['nullable', 'integer', 'exists:users,user_id'],
        ];
    }

    public function messages(): array
    {
        return [
            'complaint_type.required' => 'نوع الشكوى مطلوب.',
            'complaint_type.in' => 'نوع الشكوى غير صحيح.',
            'description.required' => 'وصف الشكوى مطلوب.',
            'description.min' => 'يجب أن يكون وصف الشكوى على الأقل 10 أحرف.',
            'description.max' => 'يجب ألا يزيد وصف الشكوى عن 2000 حرف.',
            'related_trip_id.exists' => 'الرحلة المحددة غير موجودة.',
            'related_booking_id.exists' => 'الحجز المحدد غير موجود.',
            'related_driver_id.exists' => 'السائق المحدد غير موجود.',
            'related_passenger_id.exists' => 'الراكب المحدد غير موجود.',
        ];
    }
}