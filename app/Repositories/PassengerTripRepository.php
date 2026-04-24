<?php

namespace App\Repositories;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Collection;

class PassengerTripRepository
{
    public function listForPassenger(User $passenger): Collection
    {
        return Booking::query()
            ->with([
                'trip.status',
                'trip.startGovernorate',
                'trip.endGovernorate',
                'trip.driver.user',
                'review',
            ])
            ->where('passenger_id', $passenger->user_id)
            ->orderByDesc('created_at')
            ->get();
    }
}
