<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use RuntimeException;

class PassengerManagementService
{
    public function listPassengers(array $filters): LengthAwarePaginator
    {
        $query = User::whereHas('roles', fn($q) => $q->where('name', Role::ROLE_PASSENGER))
            ->with('roles');

        // Advanced filters
        if (!empty($filters['name'])) {
            $query->where('full_name', 'like', "%{$filters['name']}%");
        }

        if (!empty($filters['phone'])) {
            $query->where('phone', 'like', "%{$filters['phone']}%");
        }

        if (!empty($filters['email'])) {
            $query->where('email', 'like', "%{$filters['email']}%");
        }

        // Legacy search filter (searches across multiple fields)
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if (isset($filters['account_status']) && $filters['account_status'] !== '') {
            $query->where('account_status', $filters['account_status']);
        }

        $sortBy    = in_array($filters['sort_by'] ?? '', ['full_name', 'email', 'created_at', 'account_status'])
            ? $filters['sort_by']
            : 'created_at';
        $sortOrder = ($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortBy, $sortOrder)
                     ->paginate($filters['per_page'] ?? 15);
    }

    public function getPassenger(int $id): User
    {
        $user = User::whereHas('roles', fn($q) => $q->where('name', Role::ROLE_PASSENGER))
            ->with('roles')
            ->find($id);

        if (!$user) {
            throw new RuntimeException('المسافر غير موجود.', 404);
        }

        return $user;
    }

    public function toggleStatus(int $id, User $actor): User
    {
        $user = $this->getPassenger($id);

        $oldStatus = $user->account_status;
        $newStatus = $oldStatus === User::STATUS_ACTIVE ? User::STATUS_INACTIVE : User::STATUS_ACTIVE;

        $user->update(['account_status' => $newStatus]);

        AuditLog::create([
            'actor_user_id' => $actor->user_id,
            'action'        => 'passenger.status_toggled',
            'entity_type'   => User::class,
            'entity_id'     => $user->user_id,
            'old_value'     => ['account_status' => $oldStatus],
            'new_value'     => ['account_status' => $newStatus],
            'description'   => "Passenger {$user->full_name} (ID: {$user->user_id}) status changed from {$oldStatus} to {$newStatus} by {$actor->full_name} (ID: {$actor->user_id}).",
        ]);

        return $user->fresh('roles');
    }
}
