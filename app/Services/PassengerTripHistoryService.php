<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\PassengerTripRepository;

class PassengerTripHistoryService
{
    public function __construct(private readonly PassengerTripRepository $passengerTripRepository)
    {
    }

    public function listTrips(User $passenger): array
    {
        $items = $this->passengerTripRepository
            ->listForPassenger($passenger)
            ->map(function ($booking) {
                return [
                    'booking_id' => $booking->booking_id,
                    'booking_code' => $booking->booking_code,
                    'trip_id' => $booking->trip_id,
                    'status' => [
                        'key' => $booking->trip?->status?->status_key,
                        'name' => $booking->trip?->status?->status_name,
                    ],
                    'route' => [
                        'from' => $booking->trip?->startGovernorate?->name,
                        'to' => $booking->trip?->endGovernorate?->name,
                    ],
                    'driver' => [
                        'user_id' => $booking->trip?->driver?->user?->user_id,
                        'full_name' => $booking->trip?->driver?->user?->full_name,
                        'phone' => $booking->trip?->driver?->user?->phone,
                    ],
                    'departure_time' => $booking->trip?->departure_time?->toIso8601String(),
                    'booking_type' => $booking->booking_type,
                    'seats_reserved' => $booking->seats_reserved,
                    'total_amount' => $booking->total_amount !== null ? (float) $booking->total_amount : null,
                    'is_rated' => $booking->review !== null,
                    'rating_endpoint' => '/api/v1/passenger/rate-trip/'.$booking->trip_id,
                    'created_at' => $booking->created_at?->toIso8601String(),
                ];
            })
            ->values();

        return [
            'items' => $items,
            'total' => $items->count(),
        ];
    }
}
