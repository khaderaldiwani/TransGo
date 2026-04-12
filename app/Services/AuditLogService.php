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
                    'description' => $log->description,
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
}
