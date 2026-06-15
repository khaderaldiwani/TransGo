<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\UserNotification;

class UserNotificationService
{
    public function __construct(private readonly NotificationDispatchService $notifications)
    {
    }

    public function notifyUser(int $userId, array $notificationData, bool $markAsSent = true): UserNotification
    {
        if ($markAsSent) {
            return $this->notifications->notifyUser($userId, $notificationData);
        }

        $notification = Notification::create($notificationData);

        return $this->notifications->attachUser($notification, $userId, false);
    }
}
