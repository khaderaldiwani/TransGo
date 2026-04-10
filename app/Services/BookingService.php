<?php

namespace App\Services;

use App\Events\BookingCreated;
use App\Models\Booking;
use App\Models\BookingPickupPoint;
use App\Models\BookingStatus;
use App\Models\Payment;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Carbon\Carbon;

class BookingService
{
    public function createBooking(array $data, User $actor): Booking
    {
        $trip = Trip::with(['points', 'driver.user', 'status'])->find($data['trip_id']);

        if (! $trip) {
            throw ValidationException::withMessages([
                'trip_id' => 'الرحلة المحددة غير موجودة.',
            ]);
        }

        if ($trip->status?->status_key === 'canceled') {
            throw ValidationException::withMessages([
                'trip_id' => 'لا يمكن الحجز على رحلة ملغاة.',
            ]);
        }

        if ($trip->is_private_booked) {
            throw ValidationException::withMessages([
                'trip_id' => 'هذه الرحلة محجوزة كخاص بالفعل.',
            ]);
        }

        if ($data['booking_type'] === 'private' && ! $trip->allow_private) {
            throw ValidationException::withMessages([
                'booking_type' => 'هذه الرحلة لا تدعم الحجز الخاص.',
            ]);
        }

        if ($data['booking_type'] === 'shared' && ! $trip->allow_shared) {
            throw ValidationException::withMessages([
                'booking_type' => 'هذه الرحلة لا تدعم الحجز المشترك.',
            ]);
        }

        $seatsReserved = isset($data['seats_reserved']) && $data['seats_reserved'] !== null
            ? (int) $data['seats_reserved']
            : 1;

        if ($seatsReserved < 1) {
            throw ValidationException::withMessages([
                'seats_reserved' => 'يجب أن يكون عدد المقاعد المحجوزة على الأقل 1.',
            ]);
        }

        if ($seatsReserved > $trip->available_seats) {
            throw ValidationException::withMessages([
                'seats_reserved' => 'عدد المقاعد المطلوبة أكبر من المقاعد المتاحة.',
            ]);
        }

        $todayBookingCount = Booking::where('passenger_id', $actor->user_id)
            ->whereDate('created_at', Carbon::today())
            ->count();

        if ($todayBookingCount >= 6) {
            throw ValidationException::withMessages([
                'booking_limit' => 'لا يمكنك حجز أكثر من 6 رحلات في اليوم الواحد.',
            ]);
        }

        $pendingStatus = BookingStatus::query()
            ->where('status_key', 'pending')
            ->where('is_active', true)
            ->first();

        if (! $pendingStatus) {
            throw new RuntimeException('حالة الحجز المبدئية غير موجودة. يرجى تجهيز بيانات الحالة أولاً.');
        }

        $totalAmount = $this->calculateTotalAmount($trip, $data['booking_type'], $seatsReserved);
        $pickupPointPayload = $this->buildPickupPointPayload($trip, $data['pickup_point']);

        return DB::transaction(function () use (
            $actor,
            $trip,
            $pendingStatus,
            $data,
            $seatsReserved,
            $totalAmount,
            $pickupPointPayload
        ) {
            $booking = Booking::create([
                'booking_code' => $this->generateBookingCode(),
                'trip_id' => $trip->trip_id,
                'passenger_id' => $actor->user_id,
                'booking_type' => $data['booking_type'],
                'seats_reserved' => $seatsReserved,
                'payment_method' => $data['payment_method'],
                'total_amount' => $totalAmount,
                'status_id' => $pendingStatus->status_id,
            ]);

            $booking->pickupPoint()->create($pickupPointPayload);

            Payment::create([
                'booking_id' => $booking->booking_id,
                'wallet_id' => null,
                'payment_method' => $data['payment_method'],
                'amount' => $totalAmount,
                'payment_status' => 'pending',
                'transaction_reference' => null,
                'failure_reason' => null,
                'paid_at' => null,
            ]);

            $trip->update([
                'available_seats' => $data['booking_type'] === 'private'
                    ? 0
                    : $trip->available_seats - $seatsReserved,
                'is_private_booked' => $data['booking_type'] === 'private' ? true : $trip->is_private_booked,
            ]);

            $booking->load(['trip.driver.user', 'pickupPoint', 'payments', 'passenger']);
            event(new BookingCreated($booking));

            return $booking;
        });
    }

    private function calculateTotalAmount(Trip $trip, string $bookingType, int $seatsReserved): float
    {
        if ($bookingType === 'private') {
            return (float) ($trip->private_price ?? $trip->system_calculated_price);
        }

        $unitPrice = $trip->shared_price ?? $trip->system_calculated_price;

        return round($unitPrice * $seatsReserved, 2);
    }

    private function buildPickupPointPayload(Trip $trip, array $pickupPoint): array
    {
        if (! empty($pickupPoint['trip_point_id'])) {
            $tripPoint = $trip->points->firstWhere('point_id', $pickupPoint['trip_point_id']);

            if (! $tripPoint) {
                throw ValidationException::withMessages([
                    'pickup_point.trip_point_id' => 'نقطة التوقف المحددة غير مرتبطة بهذه الرحلة.',
                ]);
            }

            return [
                'trip_point_id' => $tripPoint->point_id,
                'governorate_id' => $tripPoint->governorate_id,
                'point_name' => $pickupPoint['point_name'] ?? $tripPoint->point_name ?? 'نقطة الصعود',
                'address' => $pickupPoint['address'] ?? $tripPoint->address,
                'latitude' => $pickupPoint['latitude'] ?? $tripPoint->latitude,
                'longitude' => $pickupPoint['longitude'] ?? $tripPoint->longitude,
                'meeting_time' => $pickupPoint['meeting_time'] ?? null,
                'is_new' => false,
            ];
        }

        if (empty($pickupPoint['governorate_id']) || empty($pickupPoint['latitude']) || empty($pickupPoint['longitude']) || empty($pickupPoint['point_name'])) {
            throw ValidationException::withMessages([
                'pickup_point' => 'يجب تحديد نقطة صعود جديدة مع الاسم والموقع والمحافظة.',
            ]);
        }

        return [
            'trip_point_id' => null,
            'governorate_id' => $pickupPoint['governorate_id'],
            'point_name' => $pickupPoint['point_name'],
            'address' => $pickupPoint['address'] ?? null,
            'latitude' => $pickupPoint['latitude'],
            'longitude' => $pickupPoint['longitude'],
            'meeting_time' => $pickupPoint['meeting_time'] ?? null,
            'is_new' => true,
        ];
    }

    private function generateBookingCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Booking::where('booking_code', $code)->exists());

        return $code;
    }
}
