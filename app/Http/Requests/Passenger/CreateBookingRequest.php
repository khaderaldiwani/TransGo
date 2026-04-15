<?php

namespace App\Http\Requests\Passenger;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'pickup_point.point_type' => ['nullable', 'string', 'max:50'],
            'pickup_point.point_name' => ['nullable', 'string', 'max:255'],
            'pickup_point.address' => ['nullable', 'string', 'max:1000'],
            'pickup_point.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_point.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'pickup_point.note' => ['nullable', 'string', 'max:500'],
            'pickup_point.meeting_time' => ['nullable', 'date'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function (Validator $validator) {
            $bookingType = (string) $this->input('booking_type');
            $pickupPoint = $this->input('pickup_point', []);

            if ($bookingType === 'shared' && ! $this->filled('seats_reserved')) {
                $validator->errors()->add('seats_reserved', 'عدد المقاعد مطلوب للحجز المشترك.');
            }

            if ($bookingType === 'private' && $this->filled('seats_reserved')) {
                $validator->errors()->add('seats_reserved', 'لا يجب إرسال عدد المقاعد عند الحجز الخاص.');
            }

            if (! is_array($pickupPoint)) {
                return;
            }

            $hasExistingTripPoint = ! empty($pickupPoint['trip_point_id']);
            $hasNewPointPayload = isset($pickupPoint['latitude'], $pickupPoint['longitude']);

            if (! $hasExistingTripPoint && ! $hasNewPointPayload) {
                $validator->errors()->add(
                    'pickup_point',
                    'يجب اختيار نقطة توقف من المسار أو إنشاء نقطة جديدة على الخريطة.'
                );
            }

            if ($hasExistingTripPoint && $hasNewPointPayload) {
                $validator->errors()->add(
                    'pickup_point',
                    'اختر نقطة توقف موجودة أو أنشئ نقطة جديدة، ولا ترسل الخيارين معاً.'
                );
            }

            if ($hasNewPointPayload && empty($pickupPoint['note']) && empty($pickupPoint['point_name'])) {
                $validator->errors()->add(
                    'pickup_point.note',
                    'يجب إدخال ملاحظة أو اسم لنقطة التوقف الجديدة.'
                );
            }
        });
    }
}
