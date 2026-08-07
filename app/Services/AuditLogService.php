<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class AuditLogService
{
    public function log(
        User|int|null $actor,
        string $action,
        string $entityType,
        int $entityId,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $description = null
    ): AuditLog {
        return AuditLog::create([
            'actor_user_id' => $actor instanceof User ? $actor->user_id : $actor,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'description' => $description,
        ]);
    }

    public function list(array $filters): LengthAwarePaginator
    {
        $query = AuditLog::query()
            ->with('actor.roles')
            ->orderByDesc('created_at');

        if (! empty($filters['actor_name'])) {
            $actorName = trim((string) $filters['actor_name']);
            $query->whereHas('actor', fn ($actorQuery) => $actorQuery->where('full_name', 'like', "%{$actorName}%"));
        }

        if (! empty($filters['action'])) {
            $query->where('action', 'like', '%'.trim((string) $filters['action']).'%');
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $logs = $query->paginate($filters['per_page'] ?? 15);

        $logs->setCollection(
            $logs->getCollection()->map(function (AuditLog $log) {
                $actor = $log->actor;
                $actorRoles = $actor?->roles?->pluck('name')->values()->all() ?? [];

                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'action_label' => $this->formatActionLabel($log->action),
                    'action_label_display' => $this->actionLabelDisplay($log->action),
                    'description' => $log->description,
                    'description_display' => $this->descriptionDisplay($log),
                    'actor' => [
                        'id' => $actor?->user_id,
                        'full_name' => $actor?->full_name,
                        'phone' => $actor?->phone,
                        'roles' => $actorRoles,
                        'primary_role' => $actorRoles[0] ?? null,
                    ],
                    'entity' => [
                        'type' => $log->entity_type,
                        'label' => $this->formatEntityLabel($log->entity_type),
                        'id' => $log->entity_id,
                    ],
                    'changes' => [
                        'before' => $log->old_value,
                        'after' => $log->new_value,
                    ],
                    'created_at' => [
                        'iso' => optional($log->created_at)->toIso8601String(),
                        'display' => optional($log->created_at)?->format('Y-m-d H:i:s'),
                    ],
                ];
            })
        );

        return $logs;
    }

    private function formatActionLabel(string $action): string
    {
        return Str::of($action)
            ->replace('.', ' ')
            ->replace('_', ' ')
            ->title()
            ->toString();
    }

    private function formatEntityLabel(string $entityType): string
    {
        return Str::of(class_basename($entityType))
            ->snake(' ')
            ->title()
            ->toString();
    }

    private function actionLabelDisplay(string $action): string
    {
        if ((request()->getPreferredLanguage(['ar', 'en']) ?? 'en') !== 'ar') {
            return $this->formatActionLabel($action);
        }

        return match ($action) {
            'trip.admin_cancelled' => 'إلغاء الرحلة من الإدارة',
            'trip.started' => 'بدء الرحلة',
            'trip.completed' => 'اكتمال الرحلة',
            'trip.auto_completed' => 'اكتمال الرحلة تلقائياً',
            'passenger.status_toggled' => 'تغيير حالة الراكب',
            'driver.status_toggled' => 'تغيير حالة السائق',
            'driver.created' => 'إنشاء سائق',
            'employee.created' => 'إنشاء موظف',
            'employee.updated' => 'تحديث بيانات الموظف',
            'employee.disabled' => 'تعطيل الموظف',
            'employee.enabled' => 'تفعيل الموظف',
            'wallet.topup' => 'شحن المحفظة',
            'booking.admin_status_updated' => 'تحديث حالة الحجز',
            'complaint.admin_status_updated' => 'تحديث حالة الشكوى',
            'commission_rate.updated' => 'تحديث نسبة العمولة',
            default => $this->formatActionLabel($action),
        };
    }

    private function descriptionDisplay(AuditLog $log): ?string
    {
        if ((request()->getPreferredLanguage(['ar', 'en']) ?? 'en') !== 'ar') {
            return $log->description;
        }

        $actorName = $log->actor?->full_name ?? 'النظام';
        $oldValue = $log->old_value ?? [];
        $newValue = $log->new_value ?? [];
        $oldStatus = $this->statusDisplay($oldValue['account_status_text'] ?? $oldValue['status_key'] ?? $oldValue['status'] ?? null);
        $newStatus = $this->statusDisplay($newValue['account_status_text'] ?? $newValue['status_key'] ?? $newValue['status'] ?? null);

        return match ($log->action) {
            'trip.admin_cancelled' => "تم إلغاء الرحلة رقم {$log->entity_id} إدارياً.",
            'trip.started' => "تم بدء الرحلة رقم {$log->entity_id}.",
            'trip.completed' => "تم إكمال الرحلة رقم {$log->entity_id}.",
            'trip.auto_completed' => "تم إكمال الرحلة رقم {$log->entity_id} تلقائياً.",
            'passenger.status_toggled' => "تم تغيير حالة الراكب رقم {$log->entity_id} من {$oldStatus} إلى {$newStatus} بواسطة {$actorName}.",
            'driver.status_toggled' => "تم تغيير حالة السائق رقم {$log->entity_id} من {$oldStatus} إلى {$newStatus} بواسطة {$actorName}.",
            'driver.created' => "تم إنشاء السائق رقم {$log->entity_id} بواسطة {$actorName}.",
            'employee.created' => "تم إنشاء الموظف رقم {$log->entity_id} بواسطة {$actorName}.",
            'employee.updated' => "تم تحديث بيانات الموظف رقم {$log->entity_id} بواسطة {$actorName}.",
            'employee.disabled' => "تم تعطيل الموظف رقم {$log->entity_id} بواسطة {$actorName}.",
            'employee.enabled' => "تم تفعيل الموظف رقم {$log->entity_id} بواسطة {$actorName}.",
            'wallet.topup' => "تم شحن المحفظة رقم {$log->entity_id} بواسطة {$actorName}.",
            'booking.admin_status_updated' => "تم تحديث حالة الحجز رقم {$log->entity_id} من {$oldStatus} إلى {$newStatus}.",
            'complaint.admin_status_updated' => "تم تحديث حالة الشكوى رقم {$log->entity_id} من {$oldStatus} إلى {$newStatus}.",
            'commission_rate.updated' => "تم تحديث نسبة العمولة بواسطة {$actorName}.",
            default => $log->description,
        };
    }

    private function statusDisplay(mixed $status): string
    {
        return match ((string) $status) {
            'active' => 'نشط',
            'inactive' => 'غير نشط',
            'suspended' => 'معلق',
            'pending' => 'قيد الانتظار',
            'accepted' => 'مقبول',
            'rejected' => 'مرفوض',
            'canceled', 'cancelled' => 'ملغى',
            'completed' => 'مكتمل',
            'new' => 'جديد',
            'in_progress' => 'قيد المعالجة',
            default => (string) $status,
        };
    }
}
