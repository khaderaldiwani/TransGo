<?php

namespace App\Repositories;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Support\Collection;

class ComplaintRepository
{
    public function listForUser(User $user): Collection
    {
        return Complaint::query()
            ->with([
                'trip.status',
                'booking.status',
                'driver',
                'passenger',
            ])
            ->where('complainant_id', $user->user_id)
            ->orderByDesc('created_at')
            ->get();
    }
}
