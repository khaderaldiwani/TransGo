<?php

namespace App\Repositories;

use App\Models\Booking;
use App\Models\DriverReview;
use App\Models\Role;
use App\Models\TripStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use RuntimeException;

class RatingRepository
{
    public function findCompletedPassengerBookingForTrip(int $tripId, int $passengerId): ?Booking
    {
        return Booking::query()
            ->with(['trip.status', 'review'])
            ->where('trip_id', $tripId)
            ->where('passenger_id', $passengerId)
            ->whereHas('status', fn ($query) => $query->where('status_key', 'completed'))
            ->whereHas('trip.status', fn ($query) => $query->whereIn('status_key', [
                TripStatus::COMPLETED,
                TripStatus::AUTO_COMPLETED,
            ]))
            ->first();
    }

    public function createDriverRating(Booking $booking, int $stars, ?string $comment): DriverReview
    {
        return DriverReview::create([
            'booking_id' => $booking->booking_id,
            'driver_id' => $booking->trip->driver_id,
            'passenger_id' => $booking->passenger_id,
            'rated_user_type' => Role::ROLE_DRIVER,
            'rating' => $stars,
            'comment' => $comment,
            'is_visible' => true,
        ]);
    }

    public function findVisibleDriverRating(int $driverId): array
    {
        $reviews = DriverReview::query()
            ->where('driver_id', $driverId)
            ->where('rated_user_type', Role::ROLE_DRIVER)
            ->where('is_visible', true);

        $average = (float) ((clone $reviews)->avg('rating') ?? 0);
        $total = (int) (clone $reviews)->count();

        $breakdown = collect(range(1, 5))
            ->mapWithKeys(fn (int $stars) => [
                (string) $stars => (int) (clone $reviews)->where('rating', $stars)->count(),
            ])
            ->all();

        $comments = (clone $reviews)
            ->with(['passenger.roles', 'booking.trip'])
            ->whereNotNull('comment')
            ->where('comment', '!=', '')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (DriverReview $review) => $this->transformComment($review));

        return [
            'average' => round($average, 2),
            'total' => $total,
            'breakdown' => $breakdown,
            'comments' => $comments,
        ];
    }

    public function syncDriverStoredRating(int $driverId): void
    {
        $average = DriverReview::query()
            ->where('driver_id', $driverId)
            ->where('rated_user_type', Role::ROLE_DRIVER)
            ->where('is_visible', true)
            ->avg('rating');

        User::query()
            ->where('user_id', $driverId)
            ->update([
                'rating' => $average !== null ? round((float) $average, 2) : User::DEFAULT_RATING,
                'rating_last_updated' => now(),
            ]);
    }

    public function getAdminRatingAnalytics(array $filters): array
    {
        $reviews = DriverReview::query()
            ->with([
                'driver.roles',
                'driver.driverProfile',
                'passenger.roles',
                'booking.trip',
            ])
            ->where('rated_user_type', Role::ROLE_DRIVER);

        // Filter by user type and optional id
        if (($filters['user_type'] ?? null) === Role::ROLE_PASSENGER) {
            if (! empty($filters['user_id'])) {
                $reviews->where('passenger_id', $filters['user_id']);
            }

            // filter by name/number on passenger
            if (! empty($filters['name'])) {
                $name = $filters['name'];
                $reviews->whereHas('passenger', fn ($q) => $q->where('full_name', 'like', "%{$name}%"));
            }

            if (! empty($filters['number'])) {
                $number = $filters['number'];
                $reviews->whereHas('passenger', fn ($q) => $q->where('phone', 'like', "%{$number}%"));
            }
        } else {
            if (! empty($filters['user_id'])) {
                $reviews->where('driver_id', $filters['user_id']);
            }

            // filter by name/number on driver
            if (! empty($filters['name'])) {
                $name = $filters['name'];
                $reviews->whereHas('driver', fn ($q) => $q->where('full_name', 'like', "%{$name}%"));
            }

            if (! empty($filters['number'])) {
                $number = $filters['number'];
                $reviews->whereHas('driver', fn ($q) => $q->where('phone', 'like', "%{$number}%"));
            }
        }

        if (! empty($filters['from_date'])) {
            $reviews->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $reviews->whereDate('created_at', '<=', $filters['to_date']);
        }

        $visibleReviews = (clone $reviews)->where('is_visible', true);

        $average = (float) ((clone $visibleReviews)->avg('rating') ?? 0);
        $total = (int) (clone $visibleReviews)->count();

        $breakdown = collect(range(1, 5))
            ->mapWithKeys(fn (int $stars) => [
                (string) $stars => (int) (clone $visibleReviews)->where('rating', $stars)->count(),
            ])
            ->all();

        $items = (clone $reviews)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (DriverReview $review) use ($filters) {
                $classification = match (true) {
                    $review->rating >= 4 => 'good',
                    $review->rating > 2 && $review->rating < 4 => 'average',
                    default => 'low',
                };

                return [
                    'rating_id' => $review->review_id,
                    'booking_id' => $review->booking_id,
                    'trip_id' => $review->booking?->trip_id,
                    'user_type' => $filters['user_type'],
                    'rated_user_type' => $review->rated_user_type,
                    'rated_user' => $this->transformUser($review->driver),
                    'author' => $this->transformUser($review->passenger),
                    'stars' => $review->rating,
                    'classification' => $classification,
                    'comment' => $review->comment,
                    'is_visible' => (bool) $review->is_visible,
                    'hidden_at' => $review->hidden_at?->toIso8601String(),
                    'created_at' => $review->created_at?->toIso8601String(),
                ];
            })
            ->values();

        return [
            'filters' => $filters,
            'summary' => [
                'average_rating' => round($average, 2),
                'total_ratings' => $total,
                'breakdown' => $breakdown,
                'visible_ratings_count' => $items->where('is_visible', true)->count(),
                'hidden_ratings_count' => $items->where('is_visible', false)->count(),
                'classification_counts' => [
                    'good' => $items->where('classification', 'good')->where('is_visible', true)->count(),
                    'average' => $items->where('classification', 'average')->where('is_visible', true)->count(),
                    'low' => $items->where('classification', 'low')->where('is_visible', true)->count(),
                ],
            ],
            'items' => $items,
        ];
    }

    public function getAdminRatingList(array $filters): array
    {
        $reviews = DriverReview::query()
            ->with(['driver', 'passenger'])
            ->where('rated_user_type', Role::ROLE_DRIVER);

        if (($filters['user_type'] ?? null) === Role::ROLE_PASSENGER) {
            if (! empty($filters['user_id'])) {
                $reviews->where('passenger_id', $filters['user_id']);
            }

            if (! empty($filters['name'])) {
                $name = $filters['name'];
                $reviews->whereHas('passenger', fn ($q) => $q->where('full_name', 'like', "%{$name}%"));
            }

            if (! empty($filters['number'])) {
                $number = $filters['number'];
                $reviews->whereHas('passenger', fn ($q) => $q->where('phone', 'like', "%{$number}%"));
            }
        } else {
            if (! empty($filters['user_id'])) {
                $reviews->where('driver_id', $filters['user_id']);
            }

            if (! empty($filters['name'])) {
                $name = $filters['name'];
                $reviews->whereHas('driver', fn ($q) => $q->where('full_name', 'like', "%{$name}%"));
            }

            if (! empty($filters['number'])) {
                $number = $filters['number'];
                $reviews->whereHas('driver', fn ($q) => $q->where('phone', 'like', "%{$number}%"));
            }
        }

        if (! empty($filters['from_date'])) {
            $reviews->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $reviews->whereDate('created_at', '<=', $filters['to_date']);
        }

        return (clone $reviews)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (DriverReview $review) use ($filters) {
                $selectedUser = ($filters['user_type'] ?? null) === Role::ROLE_PASSENGER
                    ? $review->passenger
                    : $review->driver;

                return [
                    'rating_id' => $review->review_id,
                    'rate_date' => $review->created_at?->toIso8601String(),
                    'username' => $selectedUser?->full_name,
                    'user_type' => $filters['user_type'] ?? Role::ROLE_DRIVER,
                    'number' => $selectedUser?->phone,
                    'stars' => $review->rating,
                    'comment' => $review->comment,
                    'rating_status' => $review->is_visible ? 'visible' : 'hidden',
                    'is_visible' => (bool) $review->is_visible,
                ];
            })
            ->values()
            ->all();
    }

    public function findAdminRatingById(int $ratingId): array
    {
        $review = DriverReview::query()
            ->with([
                'driver.roles',
                'driver.driverProfile',
                'passenger.roles',
                'booking.trip',
                'hiddenBy',
            ])
            ->where('rated_user_type', Role::ROLE_DRIVER)
            ->findOrFail($ratingId);

        $classification = match (true) {
            $review->rating >= 4 => 'good',
            $review->rating > 2 && $review->rating < 4 => 'average',
            default => 'low',
        };

        return [
            'rating_id' => $review->review_id,
            'booking_id' => $review->booking_id,
            'trip_id' => $review->booking?->trip_id,
            'rated_user_type' => $review->rated_user_type,
            'rated_user' => $this->transformUser($review->driver),
            'author' => $this->transformUser($review->passenger),
            'stars' => $review->rating,
            'classification' => $classification,
            'comment' => $review->comment,
            'is_visible' => (bool) $review->is_visible,
            'hidden_at' => $review->hidden_at?->toIso8601String(),
            'hidden_by' => $review->hiddenBy ? [
                'user_id' => $review->hiddenBy->user_id,
                'full_name' => $review->hiddenBy->full_name,
            ] : null,
            'created_at' => $review->created_at?->toIso8601String(),
        ];
    }

    public function toggleRatingVisibility(int $ratingId, User $actor): DriverReview
    {
        $review = DriverReview::query()->findOrFail($ratingId);

        if ($review->is_visible) {
            $review->forceFill([
                'is_visible' => false,
                'hidden_at' => now(),
                'hidden_by' => $actor->user_id,
            ])->save();
        } else {
            $review->forceFill([
                'is_visible' => true,
                'hidden_at' => null,
                'hidden_by' => null,
            ])->save();
        }

        return $review->fresh(['driver', 'passenger', 'hiddenBy']);
    }

    public function getLowRatedDrivers(): Collection
    {
        $driverIds = DriverReview::query()
            ->select('driver_id')
            ->where('rated_user_type', Role::ROLE_DRIVER)
            ->where('is_visible', true)
            ->groupBy('driver_id')
            ->havingRaw('AVG(rating) < 2')
            ->pluck('driver_id');

        if ($driverIds->isEmpty()) {
            return new Collection();
        }

        $canceledStatusId = TripStatus::query()
            ->where('status_key', TripStatus::CANCELED)
            ->value('status_id');

        return User::query()
            ->with(['driverProfile'])
            ->whereIn('user_id', $driverIds)
            ->whereHas('roles', fn ($query) => $query->where('name', Role::ROLE_DRIVER))
            ->get()
            ->map(function (User $driver) use ($canceledStatusId) {
                $visibleReviews = DriverReview::query()
                    ->where('driver_id', $driver->user_id)
                    ->where('rated_user_type', Role::ROLE_DRIVER)
                    ->where('is_visible', true);

                $totalTrips = (int) DB::table('trips')
                    ->where('driver_id', $driver->user_id)
                    ->count();

                $canceledTrips = $canceledStatusId
                    ? (int) DB::table('trips')
                        ->where('driver_id', $driver->user_id)
                        ->where('status_id', $canceledStatusId)
                        ->count()
                    : 0;

                return [
                    'driver' => [
                        'user_id' => $driver->user_id,
                        'full_name' => $driver->full_name,
                        'phone' => $driver->phone,
                        'email' => $driver->email,
                    ],
                    'average_rating' => round((float) ((clone $visibleReviews)->avg('rating') ?? 0), 2),
                    'total_ratings' => (int) (clone $visibleReviews)->count(),
                    'total_trips' => $totalTrips,
                    'cancelled_trips' => $canceledTrips,
                    'cancellation_rate' => $totalTrips > 0 ? round(($canceledTrips / $totalTrips) * 100, 2) : 0.0,
                ];
            })
            ->sortBy('average_rating')
            ->values();
    }

    private function transformComment(DriverReview $review): array
    {
        return [
            'rating_id' => $review->review_id,
            'booking_id' => $review->booking_id,
            'trip_id' => $review->booking?->trip_id,
            'passenger' => $this->transformUser($review->passenger),
            'stars' => $review->rating,
            'comment' => $review->comment,
            'created_at' => $review->created_at?->toIso8601String(),
        ];
    }

    private function transformUser(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $roles = $user->relationLoaded('roles')
            ? $user->roles->pluck('name')->values()->all()
            : $user->roles()->pluck('name')->values()->all();

        return [
            'user_id' => $user->user_id,
            'full_name' => $user->full_name,
            'phone' => $user->phone,
            'email' => $user->email,
            'rating' => $user->rating !== null ? (float) $user->rating : null,
            'account_status' => (int) $user->account_status,
            'account_status_text' => $user->account_status_text,
            'registration_type' => $user->registration_type,
            'registration_type_text' => $user->registration_type_text,
            'roles' => $roles,
            'driver_profile' => $user->driverProfile ? [
                'address' => $user->driverProfile->address,
                'approval_status' => $user->driverProfile->approval_status,
                'personal_photo' => $user->driverProfile->personal_photo,
            ] : null,
        ];
    }
}
