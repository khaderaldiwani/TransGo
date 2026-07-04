<?php

namespace App\Services;

use App\Jobs\SendFcmTopicNotification;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Trip;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NotificationDispatchService
{
    public function __construct(
        private readonly FcmV1Service $fcm,
        private readonly FirebaseTopicService $topics
    ) {
    }

    public function notifyUser(int $userId, array $notificationData, array $fcmData = []): UserNotification
    {
        $notification = Notification::create($notificationData);

        $userNotification = $this->attachUser($notification, $userId);
        $this->sendNotificationToUserTopic($notification, $userId, $fcmData);

        return $userNotification;
    }

    public function attachUser(Notification $notification, int $userId, bool $markAsSent = true): UserNotification
    {
        return UserNotification::firstOrCreate(
            [
                'notification_id' => $notification->notification_id,
                'user_id' => $userId,
            ],
            [
                'is_read' => false,
                'is_sent' => $markAsSent,
                'sent_at' => $markAsSent ? now() : null,
            ]
        );
    }

    public function sendExistingToUser(Notification $notification, int $userId, array $data = []): array
    {
        $this->attachUser($notification, $userId);

        return $this->sendNotificationToUserTopic($notification, $userId, $data);
    }

    public function sendExistingToUsers(Notification $notification, iterable $userIds, array $data = []): void
    {
        collect($userIds)
            ->filter()
            ->unique()
            ->values()
            ->each(fn ($userId) => $this->sendExistingToUser($notification, (int) $userId, $data));
    }

    public function sendToTopic(Notification $notification, string $topic, array $data = []): array
    {
        $fcmData = $this->fcmData($notification, $data);

        if (config('services.firebase.queue', false)) {
            SendFcmTopicNotification::dispatch(
                $topic,
                $notification->title,
                $notification->body,
                $fcmData
            );

            return [
                'sent' => false,
                'queued' => true,
                'topic' => $topic,
            ];
        }

        return $this->fcm->sendToTopic(
            $topic,
            $notification->title,
            $notification->body,
            $fcmData
        );
    }

    public function sendAdminAnnouncement(User $actor, array $payload): array
    {
        if (! empty($payload['target_user_id'])) {
            return $this->sendPrivateAdminNotification($actor, $payload);
        }

        $targetRole = $payload['target_role'];
        $targetGovernorateId = $payload['target_governorate_id'] ?? null;
        $userIds = $this->resolveAudienceUserIds($targetRole, $targetGovernorateId);

        return DB::transaction(function () use ($actor, $payload, $targetRole, $targetGovernorateId, $userIds) {
            $notification = Notification::create([
                'title' => $payload['title'],
                'body' => $payload['body'],
                'notification_type' => $payload['notification_type'] ?? 'admin_general',
                'reference_type' => $payload['reference_type'] ?? null,
                'reference_id' => $payload['reference_id'] ?? null,
                'created_by' => $actor->user_id,
                'target_role' => $targetRole,
                'target_governorate_id' => $targetGovernorateId,
            ]);

            $this->sendExistingToUsers($notification, $userIds, [
                'source' => 'admin',
                'target_role' => $targetRole,
                'target_governorate_id' => $targetGovernorateId,
            ]);

            $topic = $targetGovernorateId
                ? $this->topics->roleGovernorate($targetRole, (int) $targetGovernorateId)
                : $this->topics->role($targetRole);

            $fcmResult = $this->sendToTopic($notification, $topic, [
                'source' => 'admin',
                'target_role' => $targetRole,
                'target_governorate_id' => $targetGovernorateId,
            ]);

            return [
                'notification_id' => $notification->notification_id,
                'target_topic' => $topic,
                'target_users_count' => $userIds->count(),
                'fcm' => $fcmResult,
            ];
        });
    }

    private function sendPrivateAdminNotification(User $actor, array $payload): array
    {
        return DB::transaction(function () use ($actor, $payload) {
            $targetUser = User::query()
                ->with('roles')
                ->findOrFail((int) $payload['target_user_id']);

            $targetRole = $payload['target_role']
                ?? $targetUser->roles->pluck('name')->intersect([Role::ROLE_PASSENGER, Role::ROLE_DRIVER])->first();

            $notification = Notification::create([
                'title' => $payload['title'],
                'body' => $payload['body'],
                'notification_type' => $payload['notification_type'] ?? 'admin_private',
                'reference_type' => $payload['reference_type'] ?? null,
                'reference_id' => $payload['reference_id'] ?? null,
                'created_by' => $actor->user_id,
                'target_role' => $targetRole,
                'target_governorate_id' => $payload['target_governorate_id'] ?? null,
            ]);

            $fcmResult = $this->sendExistingToUser($notification, $targetUser->user_id, [
                'source' => 'admin',
                'target_user_id' => $targetUser->user_id,
            ]);

            return [
                'notification_id' => $notification->notification_id,
                'target_topic' => $this->topics->user($targetUser->user_id),
                'target_users_count' => 1,
                'fcm' => $fcmResult,
            ];
        });
    }

    public function resolveAudienceUserIds(string $role, ?int $governorateId = null): Collection
    {
        $query = User::query()
            ->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', $role));

        if ($governorateId !== null) {
            if ($role === Role::ROLE_PASSENGER) {
                $query->whereHas('bookings.pickupPoint', fn (Builder $bookingQuery) => $bookingQuery
                    ->where('governorate_id', $governorateId));
            } elseif ($role === Role::ROLE_DRIVER) {
                $query->whereHas('trips', fn (Builder $tripQuery) => $tripQuery
                    ->where('start_governorate_id', $governorateId)
                    ->orWhere('end_governorate_id', $governorateId));
            }
        }

        return $query->pluck('user_id')->unique()->values();
    }

    public function fcmData(Notification $notification, array $extra = []): array
    {
        return [
            'notification_id' => $notification->notification_id,
            'notification_type' => $notification->notification_type,
            'reference_type' => $this->shortReferenceType($notification->reference_type),
            'reference_id' => $notification->reference_id,
            'target_role' => $notification->target_role,
            'target_governorate_id' => $notification->target_governorate_id,
            ...$extra,
        ];
    }

    public function bookingData(Booking $booking): array
    {
        return [
            'booking_id' => $booking->booking_id,
            'booking_code' => $booking->booking_code,
            'trip_id' => $booking->trip_id,
        ];
    }

    public function tripData(Trip $trip): array
    {
        return [
            'trip_id' => $trip->trip_id,
            'departure_time' => optional($trip->departure_time)->toIso8601String(),
        ];
    }

    private function sendNotificationToUserTopic(Notification $notification, int $userId, array $data = []): array
    {
        return $this->sendToTopic($notification, $this->topics->user($userId), [
            'user_id' => $userId,
            ...$data,
        ]);
    }

    private function shortReferenceType(?string $referenceType): ?string
    {
        if (! $referenceType) {
            return null;
        }

        return class_basename($referenceType);
    }
}
