<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class EmployeeManagementService
{
    public function listEmployees(array $filters): LengthAwarePaginator
    {
        $query = User::whereHas('roles', fn ($q) => $q->whereIn('name', [Role::ROLE_ADMIN, Role::ROLE_EMPLOYEE]))
            ->with('roles');

        if (! empty($filters['name'])) {
            $query->where('full_name', 'like', "%{$filters['name']}%");
        }

        if (! empty($filters['phone'])) {
            $query->where('phone', 'like', "%{$filters['phone']}%");
        }

        if (! empty($filters['email'])) {
            $query->where('email', 'like', "%{$filters['email']}%");
        }

        if (! empty($filters['search'])) {
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

        if (! empty($filters['role'])) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $filters['role']));
        }

        $sortBy = in_array($filters['sort_by'] ?? '', ['full_name', 'email', 'created_at', 'account_status'], true)
            ? $filters['sort_by']
            : 'created_at';
        $sortOrder = ($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortBy, $sortOrder)
            ->paginate($filters['per_page'] ?? 15);
    }

    public function getEmployee(int $id): User
    {
        $user = User::whereHas('roles', fn ($q) => $q->whereIn('name', [Role::ROLE_ADMIN, Role::ROLE_EMPLOYEE]))
            ->with('roles')
            ->find($id);

        if (! $user) {
            throw new RuntimeException('الموظف غير موجود.', 404);
        }

        return $user;
    }

    public function createEmployee(array $data, User $actor): User
    {
        if (! $actor->hasAnyRole([Role::ROLE_ADMIN])) {
            throw new RuntimeException('Forbidden.', 403);
        }

        $roleName = $data['role'] ?? null;
        $role = $this->resolveRole($roleName);

        return DB::transaction(function () use ($data, $actor, $role) {
            $employee = User::create([
                'full_name' => $data['full_name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'must_change_password' => true,
                'account_status' => User::STATUS_ACTIVE,
                'created_by' => $actor->user_id,
                'registration_type' => User::REGISTRATION_ADMIN,
            ]);

            $employee->roles()->attach($role->id);

            AuditLog::create([
                'actor_user_id' => $actor->user_id,
                'action' => 'employee.created',
                'entity_type' => User::class,
                'entity_id' => $employee->user_id,
                'old_value' => null,
                'new_value' => [
                    'full_name' => $employee->full_name,
                    'phone' => $employee->phone,
                    'email' => $employee->email,
                    'role' => $role->name,
                ],
                'description' => "Employee {$employee->full_name} (ID: {$employee->user_id}) created by {$actor->full_name} (ID: {$actor->user_id}).",
            ]);

            return $employee->load('roles');
        });
    }

    public function updateEmployee(int $id, array $data, User $actor): User
    {
        if (! $actor->hasAnyRole([Role::ROLE_ADMIN])) {
            throw new RuntimeException('Forbidden.', 403);
        }

        $employee = $this->getEmployee($id);
        $oldValues = [
            'full_name' => $employee->full_name,
            'phone' => $employee->phone,
            'role' => $employee->roles->pluck('name')->values()->all(),
        ];

        if (array_key_exists('full_name', $data)) {
            $employee->full_name = $data['full_name'];
        }

        if (array_key_exists('phone', $data)) {
            $employee->phone = $data['phone'];
        }

        DB::transaction(function () use ($data, $employee) {
            $employee->save();

            if (array_key_exists('role', $data)) {
                $role = $this->resolveRole($data['role']);
                $roleIdsToDetach = Role::query()
                    ->whereIn('name', [Role::ROLE_ADMIN, Role::ROLE_EMPLOYEE])
                    ->pluck('id');

                $employee->roles()->detach($roleIdsToDetach);
                $employee->roles()->attach($role->id);
            }
        });

        $employee->load('roles');

        AuditLog::create([
            'actor_user_id' => $actor->user_id,
            'action' => 'employee.updated',
            'entity_type' => User::class,
            'entity_id' => $employee->user_id,
            'old_value' => $oldValues,
            'new_value' => [
                'full_name' => $employee->full_name,
                'phone' => $employee->phone,
                'role' => $employee->roles->pluck('name')->values()->all(),
            ],
            'description' => "Employee {$employee->full_name} (ID: {$employee->user_id}) updated by {$actor->full_name} (ID: {$actor->user_id}).",
        ]);

        return $employee;
    }

    public function disableEmployee(int $id, User $actor): User
    {
        if (! $actor->hasAnyRole([Role::ROLE_ADMIN])) {
            throw new RuntimeException('Forbidden.', 403);
        }

        $employee = $this->getEmployee($id);

        if ($employee->user_id === $actor->user_id) {
            throw new RuntimeException('لا يمكنك تعطيل حسابك.', 422);
        }

        $oldStatus = $employee->account_status;
        $employee->update(['account_status' => User::STATUS_INACTIVE]);

        AuditLog::create([
            'actor_user_id' => $actor->user_id,
            'action' => 'employee.disabled',
            'entity_type' => User::class,
            'entity_id' => $employee->user_id,
            'old_value' => ['account_status' => $oldStatus],
            'new_value' => ['account_status' => $employee->account_status],
            'description' => "Employee {$employee->full_name} (ID: {$employee->user_id}) disabled by {$actor->full_name} (ID: {$actor->user_id}).",
        ]);

        $this->notifyDisabledEmployee($employee, $actor);

        return $employee->fresh('roles');
    }
public function enableEmployee(int $id, User $actor): User
    {
        if (! $actor->hasAnyRole([Role::ROLE_ADMIN])) {
            throw new RuntimeException('Forbidden.', 403);
        }

        $employee = $this->getEmployee($id);

        if ($employee->user_id === $actor->user_id) {
            throw new RuntimeException('لا يمكنك تعطيل حسابك.', 422);
        }

        $oldStatus = $employee->account_status;
        $employee->update(['account_status' => User::STATUS_ACTIVE]);

        AuditLog::create([
            'actor_user_id' => $actor->user_id,
            'action' => 'employee.enabled',
            'entity_type' => User::class,
            'entity_id' => $employee->user_id,
            'old_value' => ['account_status' => $oldStatus],
            'new_value' => ['account_status' => $employee->account_status],
            'description' => "Employee {$employee->full_name} (ID: {$employee->user_id}) enabled by {$actor->full_name} (ID: {$actor->user_id}).",
        ]);

        $this->notifyDisabledEmployee($employee, $actor);

        return $employee->fresh('roles');
    }
    private function resolveRole(?string $roleName): Role
    {
        if (! in_array($roleName, [Role::ROLE_ADMIN, Role::ROLE_EMPLOYEE], true)) {
            throw new RuntimeException('الدور الوظيفي غير صحيح.', 422);
        }

        $role = Role::where('name', $roleName)->first();

        if (! $role) {
            throw new RuntimeException('الدور المطلوب غير موجود. يرجى تشغيل Seeder للأدوار أولاً.', 500);
        }

        return $role;
    }

    private function notifyDisabledEmployee(User $employee, User $actor): void
    {
        $notification = Notification::create([
            'title' => 'تعطيل الحساب',
            'body' => 'تم تعطيل حسابك بواسطة الإدارة. يرجى التواصل مع الدعم للمساعدة.',
            'notification_type' => 'employee_disabled',
            'reference_type' => 'user',
            'reference_id' => $employee->user_id,
            'created_by' => $actor->user_id,
            'target_role' => Role::ROLE_EMPLOYEE,
        ]);

        UserNotification::firstOrCreate(
            [
                'notification_id' => $notification->notification_id,
                'user_id' => $employee->user_id,
            ],
            [
                'is_sent' => true,
                'sent_at' => now(),
            ]
        );
    }
}
