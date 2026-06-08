<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Models\UserNotification;

class UserNotificationFeedService
{
    public function listForUser(User $user, array $filters = []): array
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 15)));

        $items = UserNotification::query()
            ->with(['notification.governorate'])
            ->where('user_id', $user->user_id)
            ->whereHas('notification')
            ->join('notifications', 'notifications.notification_id', '=', 'user_notifications.notification_id')
            ->orderByDesc('notifications.created_at')
            ->orderByDesc('user_notifications.user_notification_id')
            ->select('user_notifications.*')
            ->limit($perPage)
            ->get()
            ->map(fn (UserNotification $userNotification) => $this->transform($userNotification))
            ->values();

        return [
            'items' => $items,
        ];
    }

    public function markAllAsRead(User $user): array
    {
        $updatedCount = UserNotification::query()
            ->where('user_id', $user->user_id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return [
            'updated_count' => $updatedCount,
        ];
    }

    private function transform(UserNotification $userNotification): array
    {
        /** @var Notification|null $notification */
        $notification = $userNotification->notification;

        return [
            'user_notification_id' => $userNotification->user_notification_id,
            'notification_id' => $notification?->notification_id,
            'title' => $notification?->title,
            'body' => $notification?->body,
            'notification_type' => $notification?->notification_type,
            'is_read' => (bool) $userNotification->is_read,
            'created_at' => optional($notification?->created_at)->toIso8601String(),
        ];
    }
}
