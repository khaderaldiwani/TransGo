<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

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
}
