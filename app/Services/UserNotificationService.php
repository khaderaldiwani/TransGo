<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\UserNotification;

class UserNotificationService
{
    public function notifyUser(int $userId, array $notificationData, bool $markAsSent = true): UserNotification
    {
        $notification = Notification::create($notificationData);

        return UserNotification::firstOrCreate(
            [
                'notification_id' => $notification->notification_id,
                'user_id' => $userId,
            ],
            [
                'is_sent' => $markAsSent,
                'sent_at' => $markAsSent ? now() : null,
            ]
        );
    }
}
