<?php

namespace App\Services;

use App\Models\Role;

class FirebaseTopicService
{
    public function user(int $userId): string
    {
        return 'user_'.$userId;
    }

    public function role(string $role): string
    {
        return match ($role) {
            Role::ROLE_DRIVER => 'drivers',
            Role::ROLE_PASSENGER => 'passengers',
            Role::ROLE_ADMIN => 'admins',
            Role::ROLE_EMPLOYEE => 'employees',
            default => $this->clean($role),
        };
    }

    public function roleGovernorate(string $role, int $governorateId): string
    {
        return $this->role($role).'_governorate_'.$governorateId;
    }

    public function trip(int $tripId): string
    {
        return 'trip_'.$tripId;
    }

    public function booking(int $bookingId): string
    {
        return 'booking_'.$bookingId;
    }

    private function clean(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?: 'general';

        return trim($value, '_') ?: 'general';
    }
}
