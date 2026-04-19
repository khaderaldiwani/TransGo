<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\ComplaintStatusLog;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ComplaintService
{
    /**
     * Submit a new complaint from passenger or driver
     */
    public function submitComplaint(array $data, User $user): Complaint
    {
        $data = $this->validateComplaintData($data);

        $complaint = DB::transaction(function () use ($data, $user) {
            $complaint = Complaint::create([
                'complaint_code' => $this->generateComplaintCode(),
                'complainant_id' => $user->user_id,
                'complainant_role' => $this->resolveUserRole($user),
                'complaint_type' => $data['complaint_type'],
                'related_trip_id' => $data['related_trip_id'] ?? null,
                'related_booking_id' => $data['related_booking_id'] ?? null,
                'related_driver_id' => $data['related_driver_id'] ?? null,
                'related_passenger_id' => $data['related_passenger_id'] ?? null,
                'description' => $data['description'],
                'status' => 'new',
            ]);

            // Log initial status
            ComplaintStatusLog::create([
                'complaint_id' => $complaint->complaint_id,
                'old_status' => null,
                'new_status' => 'new',
                'notes' => 'Complaint submitted',
                'changed_by' => $user->user_id,
                'changed_at' => now(),
            ]);

            // Send notification to admin (we'll implement this)
            $this->notifyAdminOfNewComplaint($complaint);

            return $complaint;
        });

        return $complaint;
    }

    /**
     * List complaints for admin with filters and pagination
     */
    public function listComplaints(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);

        $query = Complaint::query()
            ->with([
                'complainant',
                'trip.driver.user',
                'booking.passenger',
                'statusLogs' => function ($query) {
                    $query->orderBy('changed_at', 'desc');
                }
            ]);

        // Apply filters
        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if ($filters['complainant_role'] !== '') {
            $query->where('complainant_role', $filters['complainant_role']);
        }

        if ($filters['complaint_type'] !== '') {
            $query->where('complaint_type', $filters['complaint_type']);
        }

        if ($filters['from_date'] !== '') {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if ($filters['to_date'] !== '') {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        $paginator = $query->orderBy('created_at', 'desc')->paginate($filters['per_page']);

        return [
            'filters' => $filters,
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
            'summary' => [
                'complaint_count' => $paginator->total(),
            ],
            'items' => $paginator->getCollection()->map(function ($complaint) {
                return $this->transformComplaintForList($complaint);
            })->values(),
        ];
    }

    /**
     * Get detailed information for a specific complaint
     */
    public function getComplaintDetails(int $complaintId): array
    {
        $complaint = Complaint::query()
            ->with([
                'complainant',
                'trip.driver.user',
                'booking.passenger',
                'statusLogs' => function ($query) {
                    $query->with('actor')->orderBy('changed_at', 'desc');
                }
            ])
            ->findOrFail($complaintId);

        return $this->transformComplaintForDetails($complaint);
    }

    /**
     * Update complaint status by admin
     */
    public function updateComplaintStatus(int $complaintId, string $newStatus, ?string $notes, User $actor): array
    {
        // only allowed in this range
        $allowedStatuses = ['new', 'in_progress', 'completed'];

        if (!in_array($newStatus, $allowedStatuses, true)) {
            throw new RuntimeException('Invalid status provided.');
        }

        $complaint = Complaint::query()->findOrFail($complaintId);
        $oldStatus = $complaint->status;

        if ($oldStatus === $newStatus) {
            throw new RuntimeException('Complaint is already in this status.');
        }

        DB::transaction(function () use ($complaint, $newStatus, $notes, $actor, $oldStatus) {
            $complaint->status = $newStatus;

            if ($newStatus === 'completed') {
                $complaint->resolved_at = now();
            }

            $complaint->save();

            // Log status change
            ComplaintStatusLog::create([
                'complaint_id' => $complaint->complaint_id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'notes' => $notes,
                'changed_by' => $actor->user_id,
                'changed_at' => now(),
            ]);

            // Send notification to complainant
            $this->notifyComplainantOfStatusChange($complaint, $oldStatus, $newStatus, $actor);
        });

        return $this->getComplaintDetails($complaintId);
    }

    /**
     * Get audit trail for a complaint
     */
    public function getComplaintAuditTrail(int $complaintId): Collection
    {
        Complaint::findOrFail($complaintId);

        return ComplaintStatusLog::query()
            ->where('complaint_id', $complaintId)
            ->with('actor')
            ->orderBy('changed_at', 'desc')
            ->get()
            ->map(function ($log) {
                return [
                    'log_id' => $log->log_id,
                    'old_status' => $log->old_status,
                    'new_status' => $log->new_status,
                    'notes' => $log->notes,
                    'changed_by' => $log->actor?->full_name ?? 'System',
                    'changed_at' => $log->changed_at?->toIso8601String(),
                ];
            });
    }

    private function validateComplaintData(array $data): array
    {
        $allowedTypes = ['ride', 'driver', 'passenger', 'payment', 'technical', 'system'];

        if (!isset($data['complaint_type']) || !in_array($data['complaint_type'], $allowedTypes, true)) {
            throw new RuntimeException('Invalid complaint type.');
        }

        if (!isset($data['description']) || trim($data['description']) === '') {
            throw new RuntimeException('Complaint description is required.');
        }

        return $data;
    }

    private function generateComplaintCode(): string
    {
        do {
            $code = 'CMP-' . strtoupper(substr(md5(uniqid()), 0, 8));
        } while (Complaint::where('complaint_code', $code)->exists());

        return $code;
    }

    private function normalizeFilters(array $filters): array
    {
        return [
            'status' => trim((string) ($filters['status'] ?? '')),
            'complainant_role' => trim((string) ($filters['complainant_role'] ?? '')),
            'complaint_type' => trim((string) ($filters['complaint_type'] ?? '')),
            'from_date' => trim((string) ($filters['from_date'] ?? '')),
            'to_date' => trim((string) ($filters['to_date'] ?? '')),
            'per_page' => max(1, min(100, (int) ($filters['per_page'] ?? 15))),
        ];
    }

    private function resolveUserRole(User $user): string
    {
        if ($user->relationLoaded('roles')) {
            $role = $user->roles->pluck('name')->first();
        } else {
            $role = $user->roles()->pluck('name')->first();
        }

        return $role ?: 'passenger';
    }

    private function transformComplaintForList(Complaint $complaint): array
    {
        return [
            'complaint_id' => $complaint->complaint_id,
            'complaint_code' => $complaint->complaint_code,
            'complaint_type' => $complaint->complaint_type,
            'status' => $complaint->status,
            'complainant_name' => $complaint->complainant?->full_name,
            'complainant_role' => $complaint->complainant_role,
            'created_at' => $complaint->created_at?->toIso8601String(),
        ];
    }

    private function transformComplaintForDetails(Complaint $complaint): array
    {
        return [
            'complaint_info' => [
                'complaint_id' => $complaint->complaint_id,
                'complaint_code' => $complaint->complaint_code,
                'created_at' => $complaint->created_at?->toIso8601String(),
                'complaint_type' => $complaint->complaint_type,
                'status' => $complaint->status,
                'resolved_at' => $complaint->resolved_at?->toIso8601String(),
            ],
            'complainant_info' => [
                'full_name' => $complaint->complainant?->full_name,
                'role' => $complaint->complainant_role,
                'phone' => $complaint->complainant?->phone,
            ],
            'complaint_content' => [
                'description' => $complaint->description,
            ],
            'related_entities' => [
                'trip_id' => $complaint->related_trip_id,
                'booking_id' => $complaint->related_booking_id,
                'driver_id' => $complaint->related_driver_id,
                'passenger_id' => $complaint->related_passenger_id,
            ],
            'processing_log' => $complaint->statusLogs->map(function ($log) {
                return [
                    'changed_at' => $log->changed_at?->toIso8601String(),
                    'old_status' => $log->old_status,
                    'new_status' => $log->new_status,
                    'notes' => $log->notes,
                    'changed_by' => $log->actor?->full_name ?? 'System',
                ];
            })->values(),
        ];
    }

    private function notifyAdminOfNewComplaint(Complaint $complaint): void
    {
        $adminUsers = User::whereHas('roles', function ($query) {
            $query->where('name', Role::ROLE_ADMIN);
        })->get();

        foreach ($adminUsers as $admin) {
            $notification = Notification::create([
                'title' => 'شكوى جديدة',
                'body' => "تم تقديم شكوى جديدة من {$complaint->complainant->full_name}",
                'notification_type' => 'new_complaint',
                'reference_type' => 'complaint',
                'reference_id' => $complaint->complaint_id,
                'target_role' => 'admin',
            ]);

            UserNotification::firstOrCreate([
                'notification_id' => $notification->notification_id,
                'user_id' => $admin->user_id,
            ], [
                'is_sent' => true,
                'sent_at' => now(),
            ]);
        }
    }

    private function notifyComplainantOfStatusChange(Complaint $complaint, string $oldStatus, string $newStatus, User $actor): void
    {
        $statusMessages = [
            'in_progress' => 'تم تحديث حالة شكواك إلى قيد المعالجة',
            'completed' => 'تم إغلاق شكواك بنجاح',
        ];

        $message = $statusMessages[$newStatus] ?? 'تم تحديث حالة شكواك';

        $notification = Notification::create([
            'title' => 'تحديث حالة الشكوى',
            'body' => $message,
            'notification_type' => 'complaint_status_update',
            'reference_type' => 'complaint',
            'reference_id' => $complaint->complaint_id,
            'target_role' => $complaint->complainant_role,
        ]);

        UserNotification::firstOrCreate([
            'notification_id' => $notification->notification_id,
            'user_id' => $complaint->complainant_id,
        ], [
            'is_sent' => true,
            'sent_at' => now(),
        ]);
    }
}