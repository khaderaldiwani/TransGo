<?php

namespace App\Http\Requests\Passenger;

use Illuminate\Foundation\Http\FormRequest;

class CreateBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'trip_id' => ['required', 'integer', 'exists:trips,trip_id'],
            'booking_type' => ['required', 'string', 'in:shared,private'],
            'seats_reserved' => ['nullable', 'integer', 'min:1'],
            'payment_method' => ['required', 'string', 'in:electronic,cash'],
            'pickup_point' => ['required', 'array'],
            'pickup_point.trip_point_id' => ['nullable', 'integer', 'exists:trip_points,point_id'],
            'pickup_point.is_new' => ['sometimes', 'boolean'],
            'pickup_point.governorate_id' => ['nullable', 'integer', 'exists:governorates,governorate_id'],
            'pickup_point.point_name' => ['nullable', 'string', 'max:255'],
            'pickup_point.address' => ['nullable', 'string', 'max:1000'],
            'pickup_point.latitude' => ['nullable', 'numeric'],
            'pickup_point.longitude' => ['nullable', 'numeric'],
            'pickup_point.meeting_time' => ['nullable', 'date'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $data = $this->all();

            if (! empty($data['booking_type']) && $data['booking_type'] === 'shared' && empty($data['seats_reserved'])) {
                $validator->errors()->add('seats_reserved', 'يجب تحديد عدد المقاعد للحجز المشترك.');
            }

            if (empty($data['pickup_point']['trip_point_id']) && empty($data['pickup_point']['governorate_id'])) {
                $validator->errors()->add('pickup_point', 'يجب تحديد نقطة توقف موجودة أو إضافة نقطة جديدة.');
            }

            if (empty($data['pickup_point']['trip_point_id']) && ! empty($data['pickup_point']['governorate_id'])) {
                if (empty($data['pickup_point']['point_name']) || ! isset($data['pickup_point']['latitude']) || ! isset($data['pickup_point']['longitude'])) {
                    $validator->errors()->add('pickup_point', 'يجب تحديد اسم الموقع وخط العرض وخط الطول لنقطة التوقف الجديدة.');
                }
            }
        });
    }
}
