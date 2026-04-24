<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\ComplaintRepository;

class UserComplaintHistoryService
{
    public function __construct(private readonly ComplaintRepository $complaintRepository)
    {
    }

    public function listForUser(User $user): array
    {
        $items = $this->complaintRepository
            ->listForUser($user)
            ->map(function ($complaint) {
                return [
                    'complaint_id' => $complaint->complaint_id,
                    'complaint_code' => $complaint->complaint_code,
                    'complaint_type' => $complaint->complaint_type,
                    'status' => $complaint->status,
                    'description' => $complaint->description,
                    'related_trip_id' => $complaint->related_trip_id,
                    'related_booking_id' => $complaint->related_booking_id,
                    'related_driver' => $complaint->driver?->full_name,
                    'related_passenger' => $complaint->passenger?->full_name,
                    'created_at' => $complaint->created_at?->toIso8601String(),
                    'resolved_at' => $complaint->resolved_at?->toIso8601String(),
                ];
            })
            ->values();

        return [
            'items' => $items,
            'total' => $items->count(),
        ];
    }
}
